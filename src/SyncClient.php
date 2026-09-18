<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel;

use Cbox\Sync\Client\Laravel\Contracts\SyncTransport;
use Cbox\Sync\Client\Laravel\Support\Wire;
use Cbox\Sync\Client\Laravel\ValueObjects\PushOutcome;
use Cbox\Sync\Client\Laravel\ValueObjects\SyncResponse;
use Cbox\Sync\Client\Outbox;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Views\BootstrapToken;
use Cbox\Sync\Views\MultiViewClient;
use Cbox\Sync\Views\ViewCursor;

/**
 * One device's side of the protocol: drain the queue, then catch up.
 *
 * The four push outcomes are the whole point of this class. Every application
 * that talks to the protocol has to get them right, and getting one wrong is
 * either silent data loss or a queue that never moves again.
 */
class SyncClient
{
    public function __construct(
        private readonly SyncTransport $transport,
        private readonly Outbox $outbox,
        private readonly MultiViewClient $replica,
        private readonly ViewIndex $views,
    ) {}

    public function replica(): MultiViewClient
    {
        return $this->replica;
    }

    /** Where an application queues its writes. */
    public function outbox(): Outbox
    {
        return $this->outbox;
    }

    /**
     * Send queued mutations, oldest first, until the queue empties or the
     * server tells us to stop.
     */
    public function push(string $type, ?string $scope = null): PushOutcome
    {
        $sent = 0;
        $abandoned = 0;
        $resumed = null;
        $outcomes = [];

        // Only this type's queue. The outbox holds every write this device has
        // made, and the wire payload carries no type of its own - the server
        // takes it from the `type` field - so draining the whole queue here
        // would submit another type's writes as this one.
        while (($mutation = $this->outbox->head($type)) !== null) {
            $response = $this->transport->post('push', ['type' => $type, 'scope' => $scope] + Wire::mutationToWire($mutation));

            $status = $this->protocolStatus($response);
            if ($status === null) {
                // Not a protocol answer at all - an HTML error page, a proxy
                // timeout, an empty body. The queue is left exactly as it is.
                // Acknowledging would lose the write; abandoning would lose it
                // permanently, and a transient outage would drain everything.
                return new PushOutcome($sent, $abandoned, retryLater: true, outcomes: $outcomes);
            }

            if ($status === 'mutation_gap') {
                $acknowledged = $response->body->acknowledged_sequence ?? 0;
                $acknowledged = is_int($acknowledged) ? $acknowledged : 0;
                // Renumber from where the server actually is and try again.
                // Twice in a row with the same answer means resending will
                // never help, and looping against a live server is far worse
                // than stopping and letting the caller see it.
                if ($resumed === $acknowledged) {
                    return new PushOutcome($sent, $abandoned, retryLater: true, outcomes: $outcomes);
                }
                $resumed = $acknowledged;
                $this->outbox->resumeAfter($mutation, $acknowledged);

                continue;
            }
            $resumed = null;

            if ($status === 'processed') {
                $outcomes[] = $this->outcome($mutation, $response);
                $this->outbox->acknowledged($mutation);
                $sent++;

                continue;
            }

            if ($status === 'retry') {
                // Retrying is safe only under the same identity, so the queue
                // stays untouched and in order.
                return new PushOutcome($sent, $abandoned, retryLater: true, outcomes: $outcomes);
            }

            // Terminal for this identity. It leaves the queue rather than
            // blocking everything behind it forever, and the application has to
            // be told: nothing else will reveal that a write never landed.
            $this->outbox->abandon($mutation, $response->error() ?? 'rejected');
            $abandoned++;
        }

        return new PushOutcome($sent, $abandoned, retryLater: false, outcomes: $outcomes);
    }

    private function outcome(Mutation $mutation, SyncResponse $response): ValueObjects\MutationOutcome
    {
        $groups = [];
        foreach ((array) ($response->body->conflict_groups ?? []) as $group) {
            $id = is_object($group) ? ($group->id ?? null) : $group;
            if (is_string($id)) {
                $groups[] = $id;
            }
        }
        $codes = [];
        foreach ((array) ($response->body->validation ?? []) as $failure) {
            $code = is_object($failure) ? ($failure->code ?? null) : null;
            if (is_string($code)) {
                $codes[] = $code;
            }
        }
        $reason = $response->body->reason ?? null;
        $status = $response->body->status ?? null;

        return new ValueObjects\MutationOutcome(
            $mutation->id,
            $mutation->entity->type,
            $mutation->entity->id,
            // Already validated by protocolStatus() before we get here.
            MutationStatus::from(is_string($status) ? $status : ''),
            is_string($reason) ? $reason : null,
            $groups,
            $codes,
        );
    }

    /**
     * Classify an answer, refusing to act on anything that is not recognisably
     * one of ours.
     *
     * Null means "no protocol answer": the body was not a sync response at all.
     * That has to be distinguishable from a rejection, because a gateway's HTML
     * 502 and a server's considered refusal call for opposite actions.
     */
    private function protocolStatus(SyncResponse $response): ?string
    {
        if ($response->ok()) {
            $status = $response->body->status ?? null;
            if (! is_string($status) || MutationStatus::tryFrom($status) === null) {
                return null;
            }

            return $status === MutationStatus::MutationGap->value ? 'mutation_gap' : 'processed';
        }

        $error = $response->error();
        if ($error === null) {
            return null;
        }

        return $response->retriable() ? 'retry' : 'terminal';
    }

    /**
     * Bring one view up to date: finish any bootstrap, then follow deltas.
     *
     * A reset is honoured rather than retried. It means this device's local
     * state for that view can no longer be trusted, so continuing to apply
     * pages onto it would be building on sand.
     */
    public function pull(string $type, ?string $scope = null, int $pageSize = 100): void
    {
        $request = ['type' => $type, 'scope' => $scope];
        $cursor = $this->resume($type, $scope);

        if ($cursor === null) {
            // A bootstrap interrupted part-way is resumed with the token the
            // replica is waiting for. Opening a fresh one would produce a page
            // it refuses as out of order, every time, with no way back.
            $token = $this->pendingToken($type, $scope);
            do {
                $response = $this->transport->post('bootstrap', $request + ['page_size' => $pageSize] + ($token === null ? [] : ['token' => $token]));
                $this->guard($response);
                $page = Wire::bootstrapPage($response->body, new BootstrapToken(Support\Read::string($response->body, 'token')));
                $this->replica->applyBootstrap($page);
                $this->views->remember($type, $scope, $page->context->fingerprint());
                $token = $page->nextToken?->value;
                $cursor = $page->cursor;
            } while ($token !== null);
        }

        while ($cursor instanceof ViewCursor) {
            $response = $this->transport->post('delta', $request + ['cursor' => ['position' => $cursor->position->value, 'context' => $cursor->context->fingerprint()]]);
            $this->guard($response);
            $delta = Wire::deltaPage($response->body);
            $this->replica->applyDelta($delta);
            $cursor = $delta->hasMore ? $delta->cursor : null;
        }
    }

    /** The continuation token an interrupted bootstrap left behind, if any. */
    private function pendingToken(string $type, ?string $scope): ?string
    {
        $fingerprint = $this->views->fingerprint($type, $scope);
        if ($fingerprint === null) {
            return null;
        }
        $context = $this->replica->contextFor($fingerprint);

        return $context === null ? null : $this->replica->pendingBootstrapToken($context)?->value;
    }

    /**
     * Start a view again only when this device has never finished one.
     *
     * Reopening a bootstrap for a view already in delta is refused by the
     * replica, and rightly so: it would rewind local state that is already
     * ahead of the page being offered.
     */
    private function resume(string $type, ?string $scope): ?ViewCursor
    {
        $fingerprint = $this->views->fingerprint($type, $scope);
        if ($fingerprint === null) {
            return null;
        }
        $context = $this->replica->contextFor($fingerprint);

        return $context === null ? null : $this->replica->cursor($context);
    }

    private function guard(SyncResponse $response): void
    {
        if (! $response->ok()) {
            throw new Exceptions\SyncRequestFailed(
                $response->error() ?? 'unknown',
                $response->status,
                $response->reason(),
            );
        }
    }
}
