<?php

declare(strict_types=1);

use Cbox\Sync\Client\Laravel\HttpTransport;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\ValueObjects\EntityKey;
use Illuminate\Http\Client\Factory;

/**
 * The one shipped transport was the only one that could throw, and nothing in
 * the client caught it - so a dropped network became an uncaught exception out
 * of the host's scheduler. Every test double returned a value instead, which is
 * why the suite never saw it.
 */
it('answers a dead connection the way the client already understands', function () {
    // Nothing is listening. A real refusal, not a fake.
    $transport = new HttpTransport(new Factory, 'http://127.0.0.1:9', timeoutSeconds: 1);

    $response = $transport->post('push', ['type' => 'tasks']);

    expect($response->status)->toBe(0);
    expect($response->ok())->toBeFalse();
});

it('leaves the queue untouched when the network is gone', function () {
    $this->bindTransport(fn (): HttpTransport => new HttpTransport(new Factory, 'http://127.0.0.1:9', timeoutSeconds: 1));

    $client = $this->syncClient();
    $client->outbox()->queue(new EntityKey('team-1', 'tasks', 'q1'), MutationKind::Create, [Op::set('title', 'a')], 0);

    $outcome = $client->push('tasks', 'team-1');

    expect($outcome->retryLater)->toBeTrue();
    expect($outcome->sent)->toBe(0);
    expect($outcome->abandoned)->toBe(0);
    expect($client->outbox()->pending())->toBe(1);
});
