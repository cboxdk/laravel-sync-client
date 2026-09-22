<?php

declare(strict_types=1);

use Cbox\Sync\Client\Contracts\OutboxStore;
use Cbox\Sync\Client\Laravel\Contracts\SyncTransport;
use Cbox\Sync\Client\Laravel\Support\FileLock;
use Cbox\Sync\Client\Laravel\Tests\Fixtures\KernelTransport;
use Cbox\Sync\Client\Laravel\ValueObjects\SyncResponse;
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
    $client->outbox()->queue($this->named(new EntityKey('p1', 'nodes', 'n1')), MutationKind::Create, [
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

/**
 * A push names a scope. A write queued for another tenant used to go out under
 * it, meet a sequence already used, and be abandoned for good.
 */
it('never sends a queued write under another scope', function () {
    $client = $this->syncClientAs('alice');
    $client->outbox()->queue($client->key('nodes', 'p2', 'elsewhere'), MutationKind::Create, [Op::set('name', 'b'), Op::set('parent_id', 'p2')], 0);
    $client->outbox()->queue($client->key('nodes', 'p1', 'here'), MutationKind::Create, [Op::set('name', 'a'), Op::set('parent_id', 'p1')], 0);

    $first = $this->drain($client, 'nodes', 'p1');

    expect($first->sent)->toBe(1)->and($first->abandoned)->toBe(0)
        ->and($client->outbox()->pending())->toBe(1);

    $second = $this->drain($client, 'nodes', 'p2');
    expect($second->sent)->toBe(1)->and($client->outbox()->abandoned())->toBe([]);
});

/**
 * NodeType maps every scope to one server space. Two scopes on this device,
 * one server stream: the numbering used to collide the moment one response was
 * lost, and the write was abandoned as a protocol violation.
 */
it('keeps two scopes that share a server space apart through a lost response', function () {
    $lose = new ArrayObject(['next' => true]);
    $this->bindTransport(fn (): SyncTransport => new class($this->app, $lose) implements SyncTransport
    {
        public function __construct(private $app, private ArrayObject $lose) {}

        public function post(string $endpoint, array $body): SyncResponse
        {
            $answer = (new KernelTransport($this->app, 'alice'))->post($endpoint, $body);
            if ($endpoint === 'push' && $this->lose['next'] === true) {
                // Processed by the server, answer lost on the way back.
                $this->lose['next'] = false;

                return new SyncResponse(0, new stdClass);
            }

            return $answer;
        }
    });
    $client = $this->syncClient();
    $client->outbox()->queue($client->key('nodes', 'p1', 'a'), MutationKind::Create, [Op::set('name', 'a'), Op::set('parent_id', 'p1')], 0);
    $client->outbox()->queue($client->key('nodes', 'p2', 'b'), MutationKind::Create, [Op::set('name', 'b'), Op::set('parent_id', 'p2')], 0);

    expect($client->push('nodes', 'p1')->retryLater)->toBeTrue();
    $client->push('nodes', 'p2');
    $client->push('nodes', 'p1');

    expect($client->outbox()->abandoned())->toBe([])
        ->and($client->outbox()->pending())->toBe(0);
    $names = array_map(fn ($record): mixed => $record->value('name')->value(), app(Store::class)->scanRecords('team-1', null, 10));
    sort($names);
    expect($names)->toBe(['a', 'b']);
});

/**
 * The server restored from a backup: this device is ahead of it. A counter
 * that could only rise resent the same number forever.
 */
it('recovers when the server turns out to be behind this device', function () {
    $client = $this->syncClientAs('alice');
    $key = $client->key('nodes', 'p1', 'a');
    $client->outbox()->queue($key, MutationKind::Create, [Op::set('name', 'a'), Op::set('parent_id', 'p1')], 0);
    // As if five earlier writes had been acknowledged by a server that has since forgotten them.
    app(OutboxStore::class)->setAcknowledged($client->outbox()->stream($key), 'p1', 5);

    $outcome = $client->push('nodes', 'p1');

    expect($outcome->sent)->toBe(1)->and($client->outbox()->pending())->toBe(0);
});

/**
 * A queue worker and a scheduler draining together sent the same head under
 * the same number. The second push now stands aside for the one already
 * running.
 */
it('lets only one push run at a time on a device', function () {
    $client = $this->syncClientAs('alice');
    $client->outbox()->queue($client->key('nodes', 'p1', 'a'), MutationKind::Create, [Op::set('name', 'a'), Op::set('parent_id', 'p1')], 0);
    $held = new FileLock(config('sync-client.database').'.lock');
    expect($held->acquire())->toBeTrue();

    $blocked = $client->push('nodes', 'p1');

    expect($blocked->retryLater)->toBeTrue()
        ->and($blocked->sent)->toBe(0)
        ->and($client->outbox()->pending())->toBe(1);

    $held->release();
    expect($client->push('nodes', 'p1')->sent)->toBe(1);
});

/** The name a create got survives the process, for an application that crashed before storing it. */
it('remembers what a created record was named', function () {
    $client = $this->syncClientAs('alice');
    $handle = $client->key('nodes', 'p1', 'my-handle');
    $client->outbox()->queue($handle, MutationKind::Create, [Op::set('name', 'a'), Op::set('parent_id', 'p1')], 0);
    $client->push('nodes', 'p1');

    $this->restartDevice();

    expect($this->syncClientAs('alice')->outbox()->nameOf($handle)?->id)->toMatch('/^[0-9a-f-]{36}$/');
});

/** A parent and child both created offline: the child arrives pointing at the parent's real id. */
it('sends a child created offline pointing at its parent\'s real id', function () {
    config()->set('sync-client.references', ['nodes' => ['parent_id' => 'nodes']]);
    $client = $this->syncClientAs('alice');
    $parent = $client->key('nodes', 'p1', 'parent-handle');
    $client->outbox()->queue($parent, MutationKind::Create, [Op::set('name', 'parent'), Op::set('parent_id', 'p1')], 0);
    $client->outbox()->queue($client->key('nodes', 'p1', 'child-handle'), MutationKind::Create, [Op::set('name', 'child'), Op::set('parent_id', 'parent-handle')], 0);

    $outcome = $client->push('nodes', 'p1');

    $parentId = $outcome->named[0]->named->id;
    $child = app(Store::class)->record(new EntityKey('team-1', 'nodes', $outcome->named[1]->named->id));
    expect($child?->value('parent_id')->value())->toBe($parentId);
});

/**
 * The device's database restored from an older backup: it is BEHIND the server.
 * Every write after the restore used to reuse a number the server held, be
 * refused as a protocol violation, and be abandoned - one by one, for good.
 */
it('catches up when this device is behind the server', function () {
    $client = $this->syncClientAs('alice');
    foreach (['a', 'b'] as $id) {
        $client->outbox()->queue($client->key('nodes', 'p1', $id), MutationKind::Create, [Op::set('name', $id), Op::set('parent_id', 'p1')], 0);
    }
    $client->push('nodes', 'p1');
    // As restored from before the second write was acknowledged.
    app(OutboxStore::class)->resetAcknowledged($client->outbox()->stream($client->key('nodes', 'p1', 'x')), 'p1', 1);
    foreach (['c', 'd', 'e'] as $id) {
        $client->outbox()->queue($client->key('nodes', 'p1', $id), MutationKind::Create, [Op::set('name', $id), Op::set('parent_id', 'p1')], 0);
    }

    $outcome = $client->push('nodes', 'p1');

    expect($outcome->sent)->toBe(3)
        ->and($outcome->abandoned)->toBe(0)
        ->and($client->outbox()->pending())->toBe(0);
});
