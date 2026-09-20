<?php

declare(strict_types=1);

use Cbox\Sync\Client\Laravel\Contracts\SyncTransport;
use Cbox\Sync\Client\Laravel\Exceptions\SyncRequestFailed;
use Cbox\Sync\Client\Laravel\ValueObjects\SyncResponse;
use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\ValueObjects\EntityKey;

function openTask(string $id, string $title): array
{
    return [Op::set('title', $title), Op::set('status', 'open')];
}

/**
 * Retention is the reset a device meets in production: it was offline long
 * enough that the history its cursor asks for has been pruned away. The server
 * answers 409, and a device that cannot act on that stops syncing for good.
 */
it('rebuilds the view when the history it asks for has been pruned', function () {
    $client = $this->syncClientAs('alice');
    $outbox = $this->outbox();

    $outbox->queue($this->named(new EntityKey('team-1', 'tasks', 't1')), MutationKind::Create, openTask('t1', 'Ship it'), 0);
    $this->drain($client, 'tasks', 'team-1');
    $client->pull('tasks', 'team-1');

    expect($client->replica()->record($this->named(new EntityKey('team-1', 'tasks', 't1'))))->not->toBeNull();

    // The device goes quiet. The tenant keeps writing, and then retention runs
    // past the point this device stopped at.
    $outbox->queue($this->named(new EntityKey('team-1', 'tasks', 't2')), MutationKind::Create, openTask('t2', 'Later'), 0);
    $outbox->queue($this->named(new EntityKey('team-1', 'tasks', 't3')), MutationKind::Create, openTask('t3', 'Someday'), 0);
    $this->drain($client, 'tasks', 'team-1');

    $store = $this->app->make(Store::class);
    $store->prune('team-1', new CommitSequence($store->watermark('team-1')->value));

    $client->pull('tasks', 'team-1');

    // Both records are present, which can only be true if the view was rebuilt
    // from a fresh bootstrap rather than resumed from the dead cursor.
    expect($client->replica()->record($this->named(new EntityKey('team-1', 'tasks', 't1')))?->value('title')->value())->toBe('Ship it');
    expect($client->replica()->record($this->named(new EntityKey('team-1', 'tasks', 't2')))?->value('title')->value())->toBe('Later');
    expect($client->replica()->record($this->named(new EntityKey('team-1', 'tasks', 't3')))?->value('title')->value())->toBe('Someday');

    // And the rebuilt view keeps following deltas instead of resetting again.
    $client->pull('tasks', 'team-1');
    expect($client->replica()->record($this->named(new EntityKey('team-1', 'tasks', 't3'))))->not->toBeNull();
});

/**
 * Recovery is for a reset and nothing else. Swallowing other failures would
 * turn a rejected request into a silent full rebuild on every sync.
 */
it('lets a failure that is not a reset reach the application', function () {
    $this->bindTransport(fn (): SyncTransport => new class implements SyncTransport
    {
        public function post(string $endpoint, array $body): SyncResponse
        {
            return new SyncResponse(422, (object) ['error' => 'invalid_request', 'retriable' => false]);
        }
    });

    expect(fn () => $this->syncClient()->pull('tasks', 'team-1'))
        ->toThrow(SyncRequestFailed::class);
});

/**
 * A server that keeps resetting must not spin the device. One rebuild is
 * attempted; a second reset is the application's problem to back off from.
 */
it('gives up after one rebuild rather than looping', function () {
    $this->bindTransport(fn (): SyncTransport => new class implements SyncTransport
    {
        public function post(string $endpoint, array $body): SyncResponse
        {
            return new SyncResponse(409, (object) ['error' => 'reset_required', 'reason' => 'context_changed']);
        }
    });

    expect(fn () => $this->syncClient()->pull('tasks', 'team-1'))
        ->toThrow(SyncRequestFailed::class);
});
