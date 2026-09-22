<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\Contracts;

use Cbox\Sync\Client\Laravel\ValueObjects\RebaseChoice;
use Cbox\Sync\Client\Laravel\ValueObjects\StaleField;

/**
 * What this device does with its own edit when someone else changed the same
 * field first.
 *
 * Registering one is what turns on pull-before-push: the server refuses a
 * stale edit instead of preserving both values, the client catches up, asks
 * this policy about each field someone else changed, and sends the edit again.
 * Without one the server's resolver decides, exactly as before.
 *
 * It is asked about a field only when both sides really did change it to
 * different values. A field only this device touched, or one both set to the
 * same value, never reaches it.
 */
interface RebasePolicy
{
    public function rebase(StaleField $field): RebaseChoice;
}
