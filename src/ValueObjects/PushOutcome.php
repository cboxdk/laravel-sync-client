<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\ValueObjects;

readonly class PushOutcome
{
    public function __construct(
        public int $sent,
        public int $abandoned,
        /** The server asked us to come back; the queue is untouched and still in order. */
        public bool $retryLater,
    ) {}
}
