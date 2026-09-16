<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel;

use Cbox\Sync\Client\Laravel\Contracts\SyncTransport;
use Cbox\Sync\Client\Laravel\Support\Wire;
use Cbox\Sync\Client\Laravel\ValueObjects\PushOutcome;
use Cbox\Sync\Client\Laravel\ValueObjects\SyncResponse;
use Cbox\Sync\Client\Outbox;
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

        while (($mutation = $this->outbox->head()) !== null) {
            $response = $this->transport->post('push', ['type' => $type, 'scope' => $scope] + Wire::mutationToWire($mutation));

            if ($response->ok()) {
                $status = $response->body->status ?? null;
                if ($status === 'mutation_gap') {
                    $acknowledged = $response->body->acknowledged_sequence ?? 0;
                    $acknowledged = is_int($acknowledged) ? $acknowledged : 0;
                    // Renumber from where the server actually is and try again.
                    // Twice in a row with the same answer means resending will
                    // never help, and looping against a live server is far
                    // worse than stopping and letting the caller see it.
                    if ($resumed === $acknowledged) {
                        return new PushOutcome($sent, $abandoned, retryLater: true);
                    }
                    $resumed = $acknowledged;
                    $this->outbox->resumeAfter($acknowledged);

                    continue;
                }
                $resumed = null;
                $this->outbox->acknowledged($mutation);
                $sent++;

                continue;
            }

            if ($response->retriable()) {
                // Stop and leave the queue exactly as it is. Retrying later
                // with the SAME identity is what makes this safe; changing it
                // would apply the write twice.
                return new PushOutcome($sent, $abandoned, retryLater: true);
            }

            // Terminal for this identity. It leaves the queue rather than
            // blocking everything behind it forever, and the application has
            // to be told: nothing else will reveal that a write never landed.
            $this->outbox->abandon($mutation, $response->error() ?? 'rejected');
            $abandoned++;
        }

        return new PushOutcome($sent, $abandoned, retryLater: false);
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
            $token = null;
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
