<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\Exceptions;

class SyncRequestFailed extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status, public readonly ?string $reason = null)
    {
        parent::__construct('Sync request failed: '.$errorCode.($reason === null ? '' : ' ('.$reason.')'));
    }

    /** A reset means this view's local state is no longer trustworthy and must be rebuilt. */
    public function requiresReset(): bool
    {
        return $this->errorCode === 'reset_required';
    }
}
