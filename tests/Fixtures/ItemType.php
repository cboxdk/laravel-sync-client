<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\Tests\Fixtures;

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Laravel\Api\Contracts\SyncableType;
use Cbox\Sync\Laravel\Api\Exceptions\SyncRequestRejected;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\Views\EntityTypeView;
use Cbox\Sync\Views\ViewDefinition;

/** Items live inside a node: the scope is the node's id, and must be a node that exists. */
class ItemType implements SyncableType
{
    public function entityType(): string
    {
        return 'items';
    }

    public function space(SyncPrincipal $principal, ?string $scope): string
    {
        $node = $scope === null ? null : app(Store::class)->record(new EntityKey('team-1', 'nodes', $scope));
        if ($node === null) {
            throw SyncRequestRejected::forbidden();
        }

        return 'items:'.$scope;
    }

    public function view(SyncPrincipal $principal, ?string $scope): ViewDefinition
    {
        return EntityTypeView::of('items');
    }

    public function readableFields(SyncPrincipal $principal): array
    {
        return ['label'];
    }

    public function writableFields(SyncPrincipal $principal): array
    {
        return ['label'];
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
