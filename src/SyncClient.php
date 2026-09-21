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
use Cbox\Sync\ValueObjects\EntityKey;
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
    /**
     * Send what this device owes, then take what it is owed.
     *
     * The one call a notification handler makes. Push first: a device that
     * pulls before pushing reads a server that has not seen its own writes
     * yet, so its edits come back a round trip later and anything it shows in
     * the meantime is behind its own user.
     *
     * Nothing here depends on having been notified. Calling it on a timer is
     * the same operation, which is what lets a missed signal cost promptness
     * rather than correctness.
     */
    public function sync(string $type, ?string $scope = null, int $pageSize = 100): PushOutcome
    {
        $outcome = $this->push($type, $scope);
        $this->pull($type, $scope, $pageSize);

        return $outcome;
    }

    /**
     * The space this view lives in, once this device has seen it.
     *
     * A change notification names a space, because that is the boundary the log
     * is kept in - but a client works in types and scopes and cannot map one to
     * the other: the mapping is the server's own authorization policy. The
     * context saved during bootstrap carries it, so after the first sync a
     * device knows which channel to listen on.
     *
     * Null before that first sync. Subscribe after it, or subscribe to the
     * scopes your application already knows and let this confirm them.
     */
    public function space(string $type, ?string $scope = null): ?string
    {
        $fingerprint = $this->views->fingerprint($type, $scope);
        if ($fingerprint === null) {
            return null;
        }

        return $this->replica->contextFor($fingerprint)?->space;
    }

    public function push(string $type, ?string $scope = null): PushOutcome
    {
        $sent = 0;
        $abandoned = 0;
        $resumed = null;
        $outcomes = [];
        $named = [];

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
                return new PushOutcome($sent, $abandoned, retryLater: true, outcomes: $outcomes, named: $named);
            }

            if ($status === 'mutation_gap') {
                $acknowledged = $response->body->acknowledged_sequence ?? 0;
                $acknowledged = is_int($acknowledged) ? $acknowledged : 0;
                // Renumber from where the server actually is and try again.
                // Twice in a row with the same answer means resending will
                // never help, and looping against a live server is far worse
                // than stopping and letting the caller see it.
                if ($resumed === $acknowledged) {
                    return new PushOutcome($sent, $abandoned, retryLater: true, outcomes: $outcomes, named: $named);
                }
                $resumed = $acknowledged;
                $this->outbox->resumeAfter($mutation, $acknowledged);

                continue;
            }
            $resumed = null;

            if ($status === 'processed') {
                $outcomes[] = $this->outcome($mutation, $response);
                $this->outbox->acknowledged($mutation);
                $rename = $this->named($mutation, $response);
                if ($rename !== null) {
                    $named[] = $rename;
                }
                $sent++;

                continue;
            }

            if ($status === 'retry') {
                // Retrying is safe only under the same identity, so the queue
                // stays untouched and in order.
                return new PushOutcome($sent, $abandoned, retryLater: true, outcomes: $outcomes, named: $named);
            }

            // Terminal for this identity. It leaves the queue rather than
            // blocking everything behind it forever, and the application has to
            // be told: nothing else will reveal that a write never landed.
            $this->outbox->abandon($mutation, $response->error() ?? 'rejected');
            $abandoned++;
        }

        return new PushOutcome($sent, $abandoned, retryLater: false, outcomes: $outcomes, named: $named);
    }

    /**
     * A create went out under a handle the device made up, and the server
     * answered with the name it gave the record.
     *
     * Everything still queued behind that create refers to the handle and would
     * be a write to a record that does not exist, so the queue is renamed here.
     * The replica is untouched on purpose: it only ever holds records that came
     * back from the server, so it never knew the handle.
     */
    private function named(Mutation $mutation, SyncResponse $response): ?ValueObjects\RecordNamed
    {
        $name = $response->body->id ?? null;
        if (! is_string($name) || $name === '' || $name === $mutation->entity->id) {
            return null;
        }

        $named = new EntityKey($mutation->entity->space, $mutation->entity->type, $name);
        $this->outbox->rekey($mutation->entity, $named);

        return new ValueObjects\RecordNamed($mutation->entity, $named);
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
     * pages onto it would be building on sand. Local knowledge for the view is
     * dropped and the view is rebuilt from a fresh bootstrap.
     *
     * The rebuild is attempted exactly once. A second reset means the server
     * changed the view again while this device was rebuilding it, and retrying
     * in a loop would spin against a moving target rather than letting the
     * application decide to back off.
     */
    public function pull(string $type, ?string $scope = null, int $pageSize = 100): void
    {
        try {
            $this->follow($type, $scope, $pageSize);
        } catch (Exceptions\SyncRequestFailed $failed) {
            if (! $failed->requiresReset()) {
                throw $failed;
            }

            $this->resetView($type, $scope);
            $this->follow($type, $scope, $pageSize);
        }
    }

    /**
     * Forget everything this device knows about one view.
     *
     * The replica is reset before the index entry is dropped. The other order
     * loses the fingerprint that finds the context, and the memberships that
     * context names are then unreachable for good.
     */
    private function resetView(string $type, ?string $scope): void
    {
        $fingerprint = $this->views->fingerprint($type, $scope);
        if ($fingerprint !== null) {
            $context = $this->replica->contextFor($fingerprint);
            if ($context !== null) {
                $this->replica->resetView($context);
            }
        }

        $this->views->forget($type, $scope);
    }

    private function follow(string $type, ?string $scope, int $pageSize): void
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
