<?php

declare(strict_types=1);

use Cbox\Sync\Client\Laravel\Contracts\SyncTransport;
use Cbox\Sync\Client\Laravel\SyncClient;
use Cbox\Sync\Client\Laravel\ValueObjects\SyncResponse;

/**
 * Everything between this device and the engine can answer: proxies, load
 * balancers, WAFs, a captive portal. None of them speak the protocol, and none
 * of their answers may be mistaken for one.
 */
function respondingWith(object $test, int $status, string $body): SyncClient
{
    $test->bindTransport(fn (): SyncTransport => new class($status, $body) implements SyncTransport
    {
        public function __construct(private int $status, private string $body) {}

        public function post(string $endpoint, array $body): SyncResponse
        {
            $decoded = json_decode($this->body, false, 512);

            return new SyncResponse($this->status, $decoded instanceof stdClass ? $decoded : new stdClass);
        }
    });

    return $test->syncClient();
}

it('keeps the queue when a gateway answers instead of the server', function () {
    $this->queueTask('t1');
    $outcome = respondingWith($this, 502, '<html><body>Bad Gateway</body></html>')->push('tasks', 'team-1');

    // A transient outage must not cost a write. Abandoning here would drain the
    // whole queue the moment a proxy hiccups.
    expect($outcome->retryLater)->toBeTrue();
    expect($outcome->abandoned)->toBe(0);
    expect($this->outbox()->pending())->toBe(1);
});

it('does not treat a 200 that is not a protocol answer as an acknowledgement', function () {
    $this->queueTask('t1');
    $outcome = respondingWith($this, 200, '<html>Logged out</html>')->push('tasks', 'team-1');

    // A captive portal or a login redirect can answer 200 with anything. The
    // server never acknowledged this write, so it stays queued.
    expect($outcome->sent)->toBe(0);
    expect($outcome->retryLater)->toBeTrue();
    expect($this->outbox()->pending())->toBe(1);
});

it('does not treat a 200 with an unknown status as an acknowledgement', function () {
    $this->queueTask('t1');
    $outcome = respondingWith($this, 200, '{"status":"something_else"}')->push('tasks', 'team-1');

    expect($outcome->sent)->toBe(0);
    expect($this->outbox()->pending())->toBe(1);
});

it('still abandons a considered refusal', function () {
    $this->queueTask('t1');
    $outcome = respondingWith($this, 403, '{"error":"forbidden","retriable":false}')->push('tasks', 'team-1');

    // This one IS a protocol answer, and a terminal one.
    expect($outcome->abandoned)->toBe(1);
    expect($this->outbox()->pending())->toBe(0);
    expect($this->outbox()->abandoned()[0]['reason'])->toBe('forbidden');
});

it('retries a considered busy answer without losing its place', function () {
    $this->queueTask('t1');
    $outcome = respondingWith($this, 503, '{"error":"retry","retriable":true}')->push('tasks', 'team-1');

    expect($outcome->retryLater)->toBeTrue();
    expect($this->outbox()->pending())->toBe(1);
});
