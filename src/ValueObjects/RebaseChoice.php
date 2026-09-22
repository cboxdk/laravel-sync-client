<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\ValueObjects;

use Cbox\Sync\ValueObjects\FieldValue;

/** What a rebase policy decided for one field. */
readonly class RebaseChoice
{
    private function __construct(public ?FieldValue $value) {}

    /** Write this device's value anyway, now knowing what it replaces. */
    public static function keepMine(StaleField $field): self
    {
        return new self($field->mine);
    }

    /** Leave the other value in place; this device's edit to the field is dropped. */
    public static function takeTheirs(): self
    {
        return new self(null);
    }

    /** Write something else - a merge of the two, typically. */
    public static function use(mixed $value): self
    {
        return new self(FieldValue::of($value));
    }

    public function drops(): bool
    {
        return $this->value === null;
    }
}
