<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\ValueObjects;

use Cbox\Sync\Client\Laravel\Exceptions\SyncRequestFailed;

readonly class PushOutcome
{
    /** @var list<MutationOutcome> */
    public array $outcomes;

    /**
     * Records the server named, for creates that went out under a handle.
     *
     * @var list<RecordNamed>
     */
    public array $named;

    /**
     * @param  list<MutationOutcome>  $outcomes
     * @param  list<RecordNamed>  $named
     */
    public function __construct(
        public int $sent,
        public int $abandoned,
        /** The server asked us to come back; the queue is untouched and still in order. */
        public bool $retryLater,
        array $outcomes = [],
        array $named = [],
        /** Writes that met a newer edit and were rethought by the rebase policy before landing. */
        public int $rebased = 0,
        /**
         * The server did not recognise this device's credentials. Nothing was
         * dropped; sign in again and sync.
         */
        public bool $unauthenticated = false,
        /**
         * When the queue stopped on one write: that write's id, and what the
         * server answered - so an application can tell a queue held up by one
         * write from a device that is merely offline.
         */
        public ?string $blockedBy = null,
        public ?int $httpStatus = null,
        public ?string $error = null,
        /** Seconds the server asked to wait before trying again, when it said. */
        public ?int $retryAfter = null,
        /**
         * sync() only: the pull after the push was refused. The push's own
         * outcome above still stands - its refusals are the application's to
         * report either way.
         */
        public ?SyncRequestFailed $pullFailure = null,
    ) {
        $copy = [];
        foreach ($outcomes as $outcome) {
            $copy[] = $outcome;
        }
        $this->outcomes = $copy;

        $names = [];
        foreach ($named as $rename) {
            $names[] = $rename;
        }
        $this->named = $names;
    }

    /** This outcome, with what the pull that followed it ran into. */
    public function withPull(?SyncRequestFailed $failure): self
    {
        return new self(
            $this->sent, $this->abandoned, $this->retryLater, $this->outcomes, $this->named, $this->rebased,
            $this->unauthenticated || $failure?->errorCode === 'unauthenticated',
            $this->blockedBy, $this->httpStatus, $this->error, $this->retryAfter, $failure,
        );
    }

    /**
     * Writes that were processed but did not apply cleanly.
     *
     * An application has to surface these. "Sent" is not "saved": a rejection
     * or a conflict is a final answer the user is entitled to see, and nothing
     * else in the system will mention it.
     *
     * @return list<MutationOutcome>
     */
    public function needingAttention(): array
    {
        return array_values(array_filter($this->outcomes, fn (MutationOutcome $o): bool => ! $o->applied()));
    }
}
