<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\ValueObjects;

use Cbox\Sync\ValueObjects\EntityKey;

/**
 * A record the device created under a handle now has the name the server gave it.
 *
 * The queue is renamed for you. What cannot be is a field VALUE holding the old
 * handle - a child carrying its parent's id - because no library can know which
 * of an application's fields are references. Anything the application stored
 * under the handle, on screen or on disk, is its own to move.
 */
readonly class RecordNamed
{
    public function __construct(public EntityKey $handle, public EntityKey $named) {}
}
