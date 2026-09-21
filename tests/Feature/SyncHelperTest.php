<?php

declare(strict_types=1);

use Cbox\Sync\Client\Laravel\Contracts\SyncTransport;
use Cbox\Sync\Client\Laravel\Exceptions\SyncRequestFailed;
use Cbox\Sync\Client\Laravel\Tests\Fixtures\KernelTransport;
use Cbox\Sync\Client\Laravel\ValueObjects\SyncResponse;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\ValueObjects\EntityKey;

function queued(object $test, string $id, string $title): EntityKey
{
    $key = new EntityKey('team-1', 'tasks', $id);
    $test->outbox()->queue($key, MutationKind::Create, [Op::set('title', $title), Op::set('status', 'open')], 0);

    return $key;
}

/**
 * The one call a notification handler makes: send what this device owes, then
 * take what it is owed.
 */
it('sends and receives in one call', function () {
    $client = $this->syncClientAs('alice');
    queued($this, 'h1', 'Mine');

    $outcome = $client->sync('tasks', 'team-1');

    expect($outcome->sent)->toBe(1);
    expect($this->outbox()->pending())->toBe(0);

    // And the device can read back what it just wrote, without a second round.
    $named = $outcome->named[0]->named;
    expect($client->replica()->record($named)?->value('title')->value())->toBe('Mine');
});

/**
 * Push before pull. A device that reads first sees a server that has not seen
 * its own writes yet, so its edits come back a round trip later and whatever it
 * shows in between is behind its own user.
 */
it('sends before it receives', function () {
    $calls = new ArrayObject;
    $this->bindTransport(fn (): SyncTransport => new class($this->app, $calls) implements SyncTransport
    {
        public function __construct(private $app, private $calls) {}

        public function post(string $endpoint, array $body): SyncResponse
        {
            $this->calls[] = $endpoint;

            return (new KernelTransport($this->app, 'alice'))->post($endpoint, $body);
        }
    });

    $client = $this->syncClient();
    queued($this, 'h1', 'Mine');

    $client->sync('tasks', 'team-1');

    expect(iterator_to_array($calls)[0])->toBe('push');
});

/**
 * A notification names a space; a client works in types and scopes. The mapping
 * is the server's authorization policy, so the client learns it from the
 * context its own bootstrap saved.
 */
it('learns which space a view lives in', function () {
    $client = $this->syncClientAs('alice');

    expect($client->space('tasks', 'team-1'))->toBeNull();

    queued($this, 'h1', 'Mine');
    $client->sync('tasks', 'team-1');

    expect($client->space('tasks', 'team-1'))->toBe('team-1');
});

/** Nothing to send is not an error; it is the common case on a quiet device. */
it('is safe to call with an empty queue', function () {
    $client = $this->syncClientAs('alice');

    $outcome = $client->sync('tasks', 'team-1');

    expect($outcome->sent)->toBe(0);
    expect($outcome->retryLater)->toBeFalse();
});

/**
 * push() has always treated a non-answer - an HTML error page, a proxy timeout,
 * a dead socket - as "leave it and come back". Reading did not: any non-2xx
 * threw, so a dropped network during a pull became an uncaught exception out of
 * the caller's scheduler. The same defect the transport itself had, one layer up.
 */
it('does not raise a dead network while reading', function () {
    $this->bindTransport(fn (): SyncTransport => new class implements SyncTransport
    {
        public function post(string $endpoint, array $body): SyncResponse
        {
            return new SyncResponse(0, new stdClass);
        }
    });

    $client = $this->syncClient();
    $client->outbox()->queue(new EntityKey('team-1', 'tasks', 'h1'), MutationKind::Create, [Op::set('title', 'a')], 0);

    $outcome = $client->sync('tasks', 'team-1');

    expect($outcome->retryLater)->toBeTrue();
    expect($client->outbox()->pending())->toBe(1);
});

/** A considered refusal is still raised: that one tells the caller something. */
it('still raises a refusal while reading', function () {
    $this->bindTransport(fn (): SyncTransport => new class implements SyncTransport
    {
        public function post(string $endpoint, array $body): SyncResponse
        {
            return new SyncResponse(403, (object) ['error' => 'forbidden', 'retriable' => false]);
        }
    });

    expect(fn () => $this->syncClient()->pull('tasks', 'team-1'))
        ->toThrow(SyncRequestFailed::class);
});
