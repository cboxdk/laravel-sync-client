<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\Support;

use Cbox\Sync\Client\Laravel\Contracts\PushLock;

/**
 * An advisory lock on a file next to the device's database.
 *
 * The database is a local file, so every process that can push for this device
 * can reach this one too, and the operating system releases the lock if the
 * process dies holding it - no stale lease to expire.
 */
class FileLock implements PushLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path) {}

    public function acquire(): bool
    {
        $handle = fopen($this->path, 'c');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open the push lock at '.$this->path);
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
