<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\Rebase;

use Cbox\Sync\Client\Laravel\Contracts\RebasePolicy;
use Cbox\Sync\Client\Laravel\ValueObjects\RebaseChoice;
use Cbox\Sync\Client\Laravel\ValueObjects\StaleField;

/**
 * The device's edit wins, as an informed write.
 *
 * Unlike a blind overwrite, the edit is sent only after this device has pulled
 * the other value, so the log records a write made against it. Right for fields
 * where the latest deliberate edit is the truth - a title, a status, a due date.
 */
class KeepMine implements RebasePolicy
{
    public function rebase(StaleField $field): RebaseChoice
    {
        return RebaseChoice::keepMine($field);
    }
}
