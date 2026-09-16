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
 * A view per parent. The scope selects which parent's children this caller
 * reads; it is never the space, which stays the tenant either way.
 */
class NodeType implements SyncableType
{
    public function entityType(): string
    {
        return 'nodes';
    }

    public function space(SyncPrincipal $principal, ?string $scope): string
    {
        return 'team-1';
    }

    public function view(SyncPrincipal $principal, ?string $scope): ViewDefinition
    {
        return FieldEqualsView::matching('under-'.($scope ?? 'root'), '1', 'parent_id', $scope ?? 'root', 'nodes');
    }

    public function readableFields(SyncPrincipal $principal): array
    {
        return ['name', 'parent_id', 'doc'];
    }

    public function writableFields(SyncPrincipal $principal): array
    {
        return ['name', 'parent_id', 'doc'];
    }

    public function mayRead(SyncPrincipal $principal, ?string $scope): bool
    {
        return true;
    }

    public function mayWrite(SyncPrincipal $principal, ?EntityRecord $record, MutationKind $kind): bool
    {
        return true;
    }
}
