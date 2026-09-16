<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\Tests\Fixtures;

use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Laravel\Api\Contracts\SyncableType;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Cbox\Sync\Views\FieldEqualsView;
use Cbox\Sync\Views\ViewDefinition;

/**
 * A host declaration for the tests: every principal belongs to team-1 only, and
 * `secret` is readable but not writable while `internal` is neither.
 */
class TaskType implements SyncableType
{
    public function entityType(): string
    {
        return 'tasks';
    }

    public function space(SyncPrincipal $principal, ?string $scope): string
    {
        // The selector is mapped through membership, never returned as-is.
        return $scope === null || $scope === 'team-1' ? 'team-1' : 'team-'.$principal->id.'-private';
    }

    public function view(SyncPrincipal $principal, ?string $scope): ViewDefinition
    {
        return FieldEqualsView::matching('open-tasks', '1', 'status', 'open', 'tasks');
    }

    public function readableFields(SyncPrincipal $principal): array
    {
        return ['title', 'status', 'meta', 'secret'];
    }

    public function writableFields(SyncPrincipal $principal): array
    {
        return ['title', 'status', 'meta'];
    }

    public function mayRead(SyncPrincipal $principal, ?string $scope): bool
    {
        return $principal->id !== 'outsider';
    }

    public function mayWrite(SyncPrincipal $principal, ?EntityRecord $record, MutationKind $kind): bool
    {
        return $principal->id !== 'reader';
    }
}
