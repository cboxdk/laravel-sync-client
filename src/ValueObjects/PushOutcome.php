<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\ValueObjects;

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
