<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\Rebase;

use Cbox\Sync\Client\Laravel\Contracts\RebasePolicy;
use Cbox\Sync\Client\Laravel\ValueObjects\RebaseChoice;
use Cbox\Sync\Client\Laravel\ValueObjects\StaleField;

/**
 * A policy written inline.
 *
 *     $client->rebaseWith(new Using(fn (StaleField $f) => $f->field === 'notes'
 *         ? RebaseChoice::use($f->theirs?->value()."\n".$f->mine->value())
 *         : RebaseChoice::keepMine($f)));
 */
class Using implements RebasePolicy
{
    /** @param \Closure(StaleField): RebaseChoice $decide */
    public function __construct(private readonly \Closure $decide) {}

    public function rebase(StaleField $field): RebaseChoice
    {
        return ($this->decide)($field);
    }
}
