<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\Contracts;

/**
 * The headers each request carries, asked for on every request.
 *
 * A token the device refreshes after its user signs in again has to be the
 * one the next request sends. Read once when the client was built, it was
 * the old one until the process restarted, and every push answered 401.
 */
interface SyncHeaders
{
    /** @return array<string, string> */
    public function headers(): array;
}
