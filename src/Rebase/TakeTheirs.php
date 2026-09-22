<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\Rebase;

use Cbox\Sync\Client\Laravel\Contracts\RebasePolicy;
use Cbox\Sync\Client\Laravel\ValueObjects\RebaseChoice;
use Cbox\Sync\Client\Laravel\ValueObjects\StaleField;

/**
 * Whoever reached the server first wins; this device's edit to that field is
 * dropped. Its edits to fields nobody else touched still land.
 */
class TakeTheirs implements RebasePolicy
{
    public function rebase(StaleField $field): RebaseChoice
    {
        return RebaseChoice::takeTheirs();
    }
}
