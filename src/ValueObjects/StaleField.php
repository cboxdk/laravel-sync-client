<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\ValueObjects;

use Cbox\Sync\ValueObjects\FieldValue;

/** One field this device edited that someone else changed first. */
readonly class StaleField
{
    public function __construct(
        public string $entityType,
        public string $entityId,
        public string $field,
        /** What this device wanted to write. */
        public FieldValue $mine,
        /**
         * What the server holds now. Null when this device may write the field
         * but not read it: the server never discloses a value past the read
         * whitelist, so the policy has to decide without it.
         */
        public ?FieldValue $theirs,
    ) {}

    public function theirsIsHidden(): bool
    {
        return $this->theirs === null;
    }
}
