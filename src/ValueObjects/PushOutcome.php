<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\ValueObjects;

readonly class PushOutcome
{
    /** @var list<MutationOutcome> */
    public array $outcomes;

    /** @param list<MutationOutcome> $outcomes */
    public function __construct(
        public int $sent,
        public int $abandoned,
        /** The server asked us to come back; the queue is untouched and still in order. */
        public bool $retryLater,
        array $outcomes = [],
    ) {
        $copy = [];
        foreach ($outcomes as $outcome) {
            $copy[] = $outcome;
        }
        $this->outcomes = $copy;
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
