<?php

declare(strict_types=1);

use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\ValueObjects\EntityKey;

function task(string $id): EntityKey
{
    return new EntityKey('team-1', 'tasks', $id);
}

it('writes offline, drains the queue, and reads its own write back', function () {
    $client = $this->syncClientAs('alice');
    $outbox = $this->outbox();

    // Offline: nothing is sent, everything is queued and durable.
    $outbox->queue(task('t1'), MutationKind::Create, [Op::set('title', 'Ship it'), Op::set('status', 'open')], 0);
    $outbox->queue(task('t2'), MutationKind::Create, [Op::set('title', 'Later'), Op::set('status', 'open')], 0);
    expect($outbox->pending())->toBe(2);

    $outcome = $client->push('tasks', 'team-1');

    expect($outcome->sent)->toBe(2);
    expect($outcome->abandoned)->toBe(0);
    expect($outcome->retryLater)->toBeFalse();
    expect($outbox->pending())->toBe(0);

    $client->pull('tasks', 'team-1');

    expect($client->replica()->record(task('t1'))?->value('title')->value())->toBe('Ship it');
    expect($client->replica()->record(task('t2'))?->value('title')->value())->toBe('Later');
});

it('follows deltas after the first sync instead of bootstrapping again', function () {
    $client = $this->syncClientAs('alice');
    $outbox = $this->outbox();

    $outbox->queue(task('t1'), MutationKind::Create, [Op::set('title', 'first'), Op::set('status', 'open')], 0);
    $client->push('tasks', 'team-1');
    $client->pull('tasks', 'team-1');

    // A second device changes it behind our back.
    $other = $this->syncClientAs('bob');
    $this->outbox();
    $other->pull('tasks', 'team-1');

    $outbox->queue(task('t1'), MutationKind::Update, [Op::set('title', 'second')], 1);
    $client->push('tasks', 'team-1');
    $client->pull('tasks', 'team-1');

    expect($client->replica()->record(task('t1'))?->value('title')->value())->toBe('second');
});

it('drops a write the server refuses instead of wedging the queue behind it', function () {
    $client = $this->syncClientAs('alice');
    $outbox = $this->outbox();

    // `secret` is readable but not writable, so the server refuses this one.
    $outbox->queue(task('t1'), MutationKind::Create, [Op::set('secret', 'nope'), Op::set('status', 'open')], 0);
    $outbox->queue(task('t2'), MutationKind::Create, [Op::set('title', 'fine'), Op::set('status', 'open')], 0);

    $outcome = $client->push('tasks', 'team-1');

    expect($outcome->abandoned)->toBe(1);
    expect($outcome->sent)->toBe(1);
    expect($outbox->pending())->toBe(0);
    expect($outbox->abandoned()[0]['reason'])->toBe('field_not_writable');

    // The good write behind it still landed.
    $client->pull('tasks', 'team-1');
    expect($client->replica()->record(task('t2'))?->value('title')->value())->toBe('fine');
});

it('keeps a conflicting write and still counts it as sent', function () {
    $alice = $this->syncClientAs('alice');
    $this->outbox()->queue(task('t1'), MutationKind::Create, [Op::set('title', 'draft'), Op::set('status', 'open')], 0);
    $this->outbox()->queue(task('t1'), MutationKind::Update, [Op::set('title', 'from alice')], 1);
    $alice->push('tasks', 'team-1');

    // A genuinely separate device, offline since version 1, writes the same field.
    $bob = $this->secondDevice('bob', 'device-2');
    $bob->outbox()->queue(task('t1'), MutationKind::Update, [Op::set('title', 'from bob')], 1);

    $outcome = $bob->push('tasks', 'team-1');

    // A conflict is an answer, not a failure: the mutation is done either way,
    // and the competing proposal is preserved on the server rather than lost.
    expect($outcome->sent)->toBe(1);
    expect($outcome->abandoned)->toBe(0);
    expect($outcome->retryLater)->toBeFalse();

    $alice->pull('tasks', 'team-1');
    expect($alice->replica()->record(task('t1'))?->value('title')->value())->toBe('from alice');
});

it('survives a restart with its queue and its synced state intact', function () {
    $client = $this->syncClientAs('alice');
    $outbox = $this->outbox();

    $outbox->queue(task('t1'), MutationKind::Create, [Op::set('title', 'synced'), Op::set('status', 'open')], 0);
    $client->push('tasks', 'team-1');
    $client->pull('tasks', 'team-1');

    // Queue a write, then "lose the process" before it is ever sent.
    $outbox->queue(task('t2'), MutationKind::Create, [Op::set('title', 'queued'), Op::set('status', 'open')], 0);
    $this->restartDevice();

    $restarted = $this->syncClientAs('alice');

    // It still knows what it synced, and still has the write it never sent.
    expect($restarted->replica()->record(task('t1'))?->value('title')->value())->toBe('synced');
    expect($this->outbox()->pending())->toBe(1);

    $restarted->push('tasks', 'team-1');
    $restarted->pull('tasks', 'team-1');

    expect($restarted->replica()->record(task('t2'))?->value('title')->value())->toBe('queued');
});
