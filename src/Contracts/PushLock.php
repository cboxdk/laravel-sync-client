<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\Contracts;

/** Keeps a device to one push at a time, across processes. */
interface PushLock
{
    /** Non-blocking: false when another push holds it. */
    public function acquire(): bool;

    public function release(): void;
}
