<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\Support;

use Cbox\Sync\Client\Laravel\Contracts\PushLock;

/** For a client that is only ever driven from one place - a test, a single worker. */
class NoLock implements PushLock
{
    public function acquire(): bool
    {
        return true;
    }

    public function release(): void {}
}
