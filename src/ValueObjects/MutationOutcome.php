<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\ValueObjects;

use Cbox\Sync\Enums\ConflictDecision;
use Cbox\Sync\Enums\MutationStatus;

/** What the server decided about one queued write. */
readonly class MutationOutcome
{
    /** @var list<string> */
    public array $conflictGroupIds;

    /** @var list<string> */
    public array $validationCodes;

    /** @var array<string, ConflictDecision> What the server's resolver decided, per field. */
    public array $decisions;

    /**
     * @param  list<string>  $conflictGroupIds
     * @param  list<string>  $validationCodes
     * @param  array<string, ConflictDecision>  $decisions
     */
    public function __construct(
        public string $mutationId,
        public string $entityType,
        public string $entityId,
        public MutationStatus $status,
        public ?string $reason = null,
        array $conflictGroupIds = [],
        array $validationCodes = [],
        array $decisions = [],
    ) {
        $settled = [];
        foreach ($decisions as $field => $decision) {
            $settled[$field] = $decision;
        }
        $this->decisions = $settled;
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

    /**
     * Whether everything this write asked for is what the server now holds.
     *
     * A field the resolver settled server-wins answers noop, and the record
     * keeps the other value - so a noop with an override is NOT applied, and
     * the user who made the edit has to be told it did not stick.
     */
    public function applied(): bool
    {
        return ($this->status === MutationStatus::Applied || $this->status === MutationStatus::Noop)
            && $this->overridden() === [];
    }

    /**
     * Whether the server refused the write outright - rejected, invalid, a
     * failed precondition. Such a write is also kept in the outbox's
     * abandoned() until dismissed, so report it from one place.
     */
    public function refused(): bool
    {
        return in_array($this->status, [MutationStatus::Rejected, MutationStatus::ValidationFailed, MutationStatus::PreconditionFailed], true);
    }

    /**
     * Fields this write set that the server kept its own value for.
     *
     * @return list<string>
     */
    public function overridden(): array
    {
        return array_keys(array_filter($this->decisions, fn (ConflictDecision $d): bool => $d === ConflictDecision::Server));
    }
}
