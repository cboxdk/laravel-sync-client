<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\ValueObjects\EntityKey;

/**
 * The outbox holds every write this device has made, across every type and
 * space. A push names one of them, so it must send only what belongs to it.
 */
it('never sends a queued write as a different entity type', function () {
    $client = $this->syncClientAs('alice');

    $client->outbox()->queue($this->named(new EntityKey('team-1', 'tasks', 't1')), MutationKind::Create, [
        Op::set('title', 'a task'), Op::set('status', 'open'),
    ], 0);
    $client->outbox()->queue($this->named(new EntityKey('team-1', 'nodes', 'n1')), MutationKind::Create, [
        Op::set('name', 'a node'), Op::set('parent_id', 'p1'),
    ], 0);

    $this->drain($client, 'tasks', 'team-1');

    $store = app(Store::class);
    // The node must not have been created as a task under its own id.
    expect($store->record($this->named(new EntityKey('team-1', 'tasks', 'n1'))))->toBeNull();
    expect($store->record($this->named(new EntityKey('team-1', 'tasks', 't1'))))->not->toBeNull();

    // And it must still be queued, waiting for a push that names its type.
    expect($client->outbox()->pending())->toBe(1);

    $this->drain($client, 'nodes', 'p1');
    expect($store->record($this->named(new EntityKey('team-1', 'nodes', 'n1'))))->not->toBeNull();
    expect($client->outbox()->pending())->toBe(0);
});
