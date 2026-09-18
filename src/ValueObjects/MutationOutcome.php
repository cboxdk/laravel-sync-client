<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\ValueObjects;

use Cbox\Sync\Enums\MutationStatus;

/** What the server decided about one queued write. */
readonly class MutationOutcome
{
    /** @var list<string> */
    public array $conflictGroupIds;

    /** @var list<string> */
    public array $validationCodes;

    /**
     * @param  list<string>  $conflictGroupIds
     * @param  list<string>  $validationCodes
     */
    public function __construct(
        public string $mutationId,
        public string $entityType,
        public string $entityId,
        public MutationStatus $status,
        public ?string $reason = null,
        array $conflictGroupIds = [],
        array $validationCodes = [],
    ) {
        $groups = [];
        foreach ($conflictGroupIds as $id) {
            $groups[] = $id;
        }
        $this->conflictGroupIds = $groups;
        $codes = [];
        foreach ($validationCodes as $code) {
            $codes[] = $code;
        }
        $this->validationCodes = $codes;
    }

    public function applied(): bool
    {
        return $this->status === MutationStatus::Applied || $this->status === MutationStatus::Noop;
    }
}
