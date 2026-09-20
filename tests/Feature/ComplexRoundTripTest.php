<?php

declare(strict_types=1);

use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\ValueObjects\EntityKey;

function node(string $id): EntityKey
{
    return new EntityKey('team-1', 'nodes', $id);
}

/** A document with every shape that canonical JSON treats as distinct. */
function awkwardDocument(): stdClass
{
    return (object) [
        'emptyObject' => new stdClass,
        'emptyArray' => [],
        'nested' => (object) [
            'numericKeys' => (object) ['2' => 'two', '10' => 'ten', '1' => 'one'],
            'list' => [1, '1', 1.0, true, null],
            'deep' => (object) ['a' => (object) ['b' => (object) ['c' => 'bottom']]],
        ],
        'unicode' => 'blåbærgrød æøå 🌍',
        'zero' => 0,
        'false' => false,
        'nullValue' => null,
    ];
}

it('carries an awkward nested document through the wire without reshaping it', function () {
    $client = $this->syncClientAs('alice');
    $client->outbox()->queue($this->named(node('n1')), MutationKind::Create, [
        Op::set('parent_id', 'p1'), Op::set('name', 'Node'), Op::set('doc', awkwardDocument()),
    ], 0);
    $this->drain($client, 'nodes', 'p1');
    $client->pull('nodes', 'p1');

    $doc = $client->replica()->record($this->named(node('n1')))?->value('doc')->value();

    // The shapes that a naive associative decode would flatten into each other.
    expect($doc?->emptyObject)->toBeInstanceOf(stdClass::class);
    expect($doc?->emptyArray)->toBe([]);
    expect($doc?->nested->list)->toBe([1, '1', 1.0, true, null]);
    expect($doc?->nested->deep->a->b->c)->toBe('bottom');
    expect($doc?->unicode)->toBe('blåbærgrød æøå 🌍');
    expect($doc?->zero)->toBe(0);
    expect($doc?->false)->toBeFalse();
    expect($doc?->nullValue)->toBeNull();
    // Numeric-looking keys stay keys, and stay strings.
    expect(get_object_vars($doc?->nested->numericKeys ?? new stdClass))->toHaveKeys(['1', '2', '10']);
});

it('treats a round-tripped document as unchanged when it is written back verbatim', function () {
    $client = $this->syncClientAs('alice');
    $client->outbox()->queue($this->named(node('n1')), MutationKind::Create, [
        Op::set('parent_id', 'p1'), Op::set('doc', awkwardDocument()),
    ], 0);
    $this->drain($client, 'nodes', 'p1');
    $client->pull('nodes', 'p1');

    $record = $client->replica()->record($this->named(node('n1'))) ?? throw new LogicException('expected a record');
    $version = $record->version->value;

    // Read it back out and write exactly what we got. If anything reshaped the
    // document on the way through, this produces a new version instead of a
    // no-op - and every client would churn a version on every sync.
    $client->outbox()->queue($this->named(node('n1')), MutationKind::Update, [Op::set('doc', $record->value('doc')->value())], $version);
    $this->drain($client, 'nodes', 'p1');
    $client->pull('nodes', 'p1');

    expect($client->replica()->record($this->named(node('n1')))?->version->value)->toBe($version);
});

it('reports a nested conflict with enough to resolve it, without the other proposal', function () {
    $alice = $this->syncClientAs('alice');
    $alice->outbox()->queue($this->named(node('n1')), MutationKind::Create, [
        Op::set('parent_id', 'p1'), Op::set('doc', (object) ['title' => 'draft', 'tags' => ['x']]),
    ], 0);
    $alice->outbox()->queue($this->named(node('n1')), MutationKind::Update, [Op::set('doc', (object) ['title' => 'alice', 'tags' => ['x']])], 1);
    $this->drain($alice, 'nodes', 'p1');

    // Bob is offline since version 1 and edits a different branch of the same
    // document. Different branch, same field: the engine cannot merge it.
    $bob = $this->secondDevice('bob', 'device-2');
    $bob->outbox()->queue($this->named(node('n1')), MutationKind::Update, [Op::set('doc', (object) ['title' => 'draft', 'tags' => ['x', 'y']])], 1);
    $outcome = $this->drain($bob, 'nodes', 'p1');

    expect($outcome->sent)->toBe(1);
    expect($outcome->abandoned)->toBe(0);

    // Alice syncs and sees the canonical branch; bob's proposal is preserved on
    // the server but deliberately not delivered by this transport.
    $alice->pull('nodes', 'p1');
    expect($alice->replica()->record($this->named(node('n1')))?->value('doc')->value()->title)->toBe('alice');
    expect($alice->replica()->record($this->named(node('n1')))?->value('doc')->value()->tags)->toBe(['x']);
});

it('moves a child between parents across two views on the same device', function () {
    $client = $this->syncClientAs('alice');
    $client->outbox()->queue($this->named(node('n1')), MutationKind::Create, [Op::set('parent_id', 'p1'), Op::set('name', 'Task')], 0);
    $this->drain($client, 'nodes', 'p1');

    $client->pull('nodes', 'p1');
    $client->pull('nodes', 'p2');
    expect($client->replica()->belongsTo($this->named(node('n1')), 'under-p1'))->toBeTrue();
    expect($client->replica()->belongsTo($this->named(node('n1')), 'under-p2'))->toBeFalse();

    $client->outbox()->queue($this->named(node('n1')), MutationKind::Update, [Op::set('parent_id', 'p2')], 1);
    $this->drain($client, 'nodes', 'p2');

    // Both views have to be pulled: one sees a removal, the other an entry.
    $client->pull('nodes', 'p1');
    $client->pull('nodes', 'p2');

    expect($client->replica()->belongsTo($this->named(node('n1')), 'under-p1'))->toBeFalse();
    expect($client->replica()->belongsTo($this->named(node('n1')), 'under-p2'))->toBeTrue();
    // It survives the removal because the other view still owns it.
    expect($client->replica()->record($this->named(node('n1'))))->not->toBeNull();
});

it('distinguishes a field set to null from one that was unset, end to end', function () {
    $client = $this->syncClientAs('alice');
    $client->outbox()->queue($this->named(node('n1')), MutationKind::Create, [Op::set('parent_id', 'p1'), Op::set('doc', (object) ['n' => 1])], 0);
    $this->drain($client, 'nodes', 'p1');
    $client->pull('nodes', 'p1');

    $client->outbox()->queue($this->named(node('n1')), MutationKind::Update, [Op::set('doc', null)], 1);
    $this->drain($client, 'nodes', 'p1');
    $client->pull('nodes', 'p1');

    $afterNull = $client->replica()->record($this->named(node('n1')))?->value('doc');
    expect($afterNull?->exists)->toBeTrue();
    expect($afterNull?->value())->toBeNull();

    $client->outbox()->queue($this->named(node('n1')), MutationKind::Update, [Op::unset('doc')], 2);
    $this->drain($client, 'nodes', 'p1');
    $client->pull('nodes', 'p1');

    $afterUnset = $client->replica()->record($this->named(node('n1')))?->value('doc');
    expect($afterUnset?->exists)->toBeFalse();
});
