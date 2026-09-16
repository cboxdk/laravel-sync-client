<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\ValueObjects;

/** One answer from the server, already decoded. */
readonly class SyncResponse
{
    /** @param array<array-key, mixed> $body */
    public function __construct(public int $status, public array $body) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function error(): ?string
    {
        $error = $this->body['error'] ?? null;

        return is_string($error) ? $error : null;
    }

    public function retriable(): bool
    {
        return ($this->body['retriable'] ?? false) === true;
    }

    public function reason(): ?string
    {
        $reason = $this->body['reason'] ?? null;

        return is_string($reason) ? $reason : null;
    }
}
