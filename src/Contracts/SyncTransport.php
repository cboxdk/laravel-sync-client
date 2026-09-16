<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\Contracts;

use Cbox\Sync\Client\Laravel\ValueObjects\SyncResponse;

/**
 * How a request reaches the server.
 *
 * An interface because the thing worth testing is the client's behaviour on
 * each outcome, and a real socket is the least interesting part of that.
 */
interface SyncTransport
{
    /** @param array<string, mixed> $body */
    public function post(string $endpoint, array $body): SyncResponse;
}
