<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\ValueObjects\EntityKey;

function handle(string $id): EntityKey
{
    return new EntityKey('team-1', 'tasks', $id);
}

/**
 * A device creates a record long before the server has seen it, so it needs
 * something to call it in the meantime. That handle is not the record's name:
 * an id a client chooses is attacker-controlled input in a key position.
 */
it('reports the name the server gave a record it created', function () {
    $client = $this->syncClientAs('alice');
    $client->outbox()->queue(handle('my-handle'), MutationKind::Create, [
        Op::set('title', 'Ship it'), Op::set('status', 'open'),
    ], 0);

    $outcome = $client->push('tasks', 'team-1');

    expect($outcome->sent)->toBe(1);
    expect($outcome->named)->toHaveCount(1);
    expect($outcome->named[0]->handle->id)->toBe('my-handle');
    expect($outcome->named[0]->named->id)->not->toBe('my-handle');

    // And that is the name the record is stored under, not the handle.
    $store = app(Store::class);
    expect($store->record(handle('my-handle')))->toBeNull();
    expect($store->record($outcome->named[0]->named)?->value('title')->value())->toBe('Ship it');
});

/**
 * Everything queued behind a create still refers to the handle. Left alone it
 * would be a write to a record that does not exist.
 */
it('renames what is already queued behind the create', function () {
    $client = $this->syncClientAs('alice');
    $outbox = $client->outbox();

    // Written offline, in one go: create then edit, before either was sent.
    $outbox->queue(handle('h1'), MutationKind::Create, [Op::set('title', 'first'), Op::set('status', 'open')], 0);
    $outbox->queue(handle('h1'), MutationKind::Update, [Op::set('title', 'second')], 1);

    $outcome = $client->push('tasks', 'team-1');

    expect($outcome->sent)->toBe(2);
    expect($outcome->abandoned)->toBe(0);
    expect($outbox->pending())->toBe(0);

    $named = $outcome->named[0]->named;
    expect(app(Store::class)->record($named)?->value('title')->value())->toBe('second');
});

/** An update names a record that already exists, so there is nothing to rename. */
it('reports no naming for a write to a record that already has one', function () {
    $client = $this->syncClientAs('alice');
    $client->outbox()->queue(handle('h2'), MutationKind::Create, [Op::set('title', 'a'), Op::set('status', 'open')], 0);
    $named = $client->push('tasks', 'team-1')->named[0]->named;

    $client->outbox()->queue($named, MutationKind::Update, [Op::set('title', 'b')], 1);
    $second = $client->push('tasks', 'team-1');

    expect($second->sent)->toBe(1);
    expect($second->named)->toBe([]);
});
