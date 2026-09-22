<?php

declare(strict_types=1);

use Cbox\Sync\Client\Laravel\ValueObjects\PushOutcome;
use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\ValueObjects\EntityKey;

/** The server-side value of a field, by type, space and id. */
function onServer(string $space, string $type, string $id, string $field): mixed
{
    return app(Store::class)->record(new EntityKey($space, $type, $id))?->value($field)->value();
}

/** @return array<string, string> handle => name, from every push outcome given */
function namesOf(PushOutcome ...$outcomes): array
{
    $names = [];
    foreach ($outcomes as $outcome) {
        foreach ($outcome->named as $rename) {
            $names[$rename->handle->id] = $rename->named->id;
        }
    }

    return $names;
}

/**
 * Parent-first used to drain the parent's whole stream, and skip any parent in
 * a stream already being drained - so a write in that stream went out holding a
 * handle. Now exactly the parent's create goes first, wherever it is queued.
 */
it('sends exactly the parent a write points at, not its whole stream', function () {
    config()->set('sync-client.references', ['tasks' => ['meta' => 'nodes'], 'nodes' => ['name' => 'tasks']]);
    $client = $this->syncClientAs('alice');
    $client->outbox()->queue($client->key('nodes', 'p1', 'N'), MutationKind::Create, [Op::set('name', 'plain'), Op::set('parent_id', 'p1')], 0);
    $client->outbox()->queue($client->key('tasks', 'team-1', 'C'), MutationKind::Create, [Op::set('title', 'c'), Op::set('status', 'open'), Op::set('meta', 'N')], 0);
    $client->outbox()->queue($client->key('tasks', 'team-1', 'T'), MutationKind::Create, [Op::set('title', 't'), Op::set('status', 'open')], 0);
    $client->outbox()->queue($client->key('nodes', 'p1', 'N'), MutationKind::Update, [Op::set('name', 'T')], 1);

    $tasks = $client->push('tasks', 'team-1');
    $nodes = $client->push('nodes', 'p1');
    $names = namesOf($tasks, $nodes);

    expect($client->outbox()->abandoned())->toBe([])
        ->and(onServer('team-1', 'tasks', $names['C'], 'meta'))->toBe($names['N'])
        ->and(onServer('team-1', 'nodes', $names['N'], 'name'))->toBe($names['T']);
});

/**
 * Items live inside a node, scoped by its id. Queued under a node created
 * offline, they are queued under its handle - which the server refuses as a
 * scope. The node goes first and the items move to its name.
 */
it('sends a parent that is a write\'s scope first, and moves the write to its name', function () {
    config()->set('sync-client.scoped_by', ['items' => 'nodes']);
    $client = $this->syncClientAs('alice');
    $client->outbox()->queue($client->key('nodes', 'p1', 'proj'), MutationKind::Create, [Op::set('name', 'project'), Op::set('parent_id', 'p1')], 0);
    $client->outbox()->queue($client->key('items', 'proj', 'i1'), MutationKind::Create, [Op::set('label', 'first')], 0);

    $outcome = $client->push('items', 'proj');
    $names = namesOf($outcome);

    expect($outcome->abandoned)->toBe(0)
        ->and($client->outbox()->pending())->toBe(0)
        ->and(onServer('items:'.$names['proj'], 'items', $names['i1'], 'label'))->toBe('first');
});

/**
 * A parent the server refused will not exist. The child used to go out anyway,
 * pointing at a handle for ever; it is abandoned with it, and both come back
 * together - under the parent's name - once the application requeues them.
 */
it('abandons a child with its refused parent, and requeues both under the parent\'s name', function () {
    config()->set('sync-client.references', ['nodes' => ['name' => 'tasks']]);
    $reader = $this->syncClientAs('reader');
    $reader->outbox()->queue($reader->key('tasks', 'team-1', 'P'), MutationKind::Create, [Op::set('title', 'parent'), Op::set('status', 'open')], 0);
    $reader->outbox()->queue($reader->key('nodes', 'p1', 'K'), MutationKind::Create, [Op::set('name', 'P'), Op::set('parent_id', 'p1')], 0);

    $reader->push('tasks', 'team-1');
    $reader->push('nodes', 'p1');
    $reasons = array_column($reader->outbox()->abandoned(), 'reason');
    expect($reasons)->toBe(['forbidden', 'parent_abandoned']);

    $alice = $this->syncClientAs('alice');
    foreach ($alice->outbox()->abandoned() as $entry) {
        $alice->requeue($entry['mutation']->id);
    }
    $first = $alice->push('tasks', 'team-1');
    $second = $alice->push('nodes', 'p1');
    $names = namesOf($first, $second);

    expect(onServer('team-1', 'nodes', $names['K'], 'name'))->toBe($names['P']);
});
