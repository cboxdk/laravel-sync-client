<?php

declare(strict_types=1);

use Cbox\Sync\Client\Laravel\Contracts\SyncTransport;
use Cbox\Sync\Client\Laravel\SyncClient;
use Cbox\Sync\Client\Laravel\ValueObjects\SyncResponse;
use Cbox\Sync\Enums\MutationStatus;

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

/**
 * An expired session is about the device, not the write. Treating the server's
 * named 401 as terminal abandoned every queued write the moment a token ran
 * out - measured: three queued, three lost.
 */
it('keeps every queued write through an expired session, and sends them after signing in', function () {
    foreach (['t1', 't2', 't3'] as $id) {
        $this->queueTask($id);
    }

    $outcome = respondingWith($this, 401, '{"error":"unauthenticated","retriable":false}')->push('tasks', 'team-1');

    expect($outcome->unauthenticated)->toBeTrue()
        ->and($outcome->retryLater)->toBeTrue()
        ->and($outcome->abandoned)->toBe(0)
        ->and($this->outbox()->pending())->toBe(3);

    $again = $this->syncClientAs('alice')->push('tasks', 'team-1');

    expect($again->sent)->toBe(3)->and($this->outbox()->pending())->toBe(0);
});

/** A refusal about the moment - a permission granted since - can be sent again as a new write. */
it('sends an abandoned write again once the application requeues it', function () {
    $this->queueTask('t1');
    respondingWith($this, 403, '{"error":"forbidden","retriable":false}')->push('tasks', 'team-1');
    $abandoned = $this->outbox()->abandoned()[0]['mutation'];

    $this->outbox()->requeue($abandoned->id);
    $outcome = $this->syncClientAs('alice')->push('tasks', 'team-1');

    expect($outcome->sent)->toBe(1)
        ->and($this->outbox()->abandoned())->toBe([])
        ->and($outcome->outcomes[0]->status)->toBe(MutationStatus::Applied);
});

/**
 * Gateways and rate limiters speak JSON too. Any body with an "error" key used
 * to count as the server's final word, and the queue drained on the first
 * hiccup.
 */
it('keeps the queue for an error it does not recognise as a refusal', function (int $status, string $body) {
    $this->queueTask('t1');

    $outcome = respondingWith($this, $status, $body)->push('tasks', 'team-1');

    expect($outcome->abandoned)->toBe(0)
        ->and($outcome->retryLater)->toBeTrue()
        ->and($this->outbox()->pending())->toBe(1);
})->with([
    'rate limited' => [429, '{"error":"too_many_requests","message":"slow down"}'],
    'gateway' => [502, '{"error":"bad_gateway"}'],
    'unknown code' => [400, '{"error":"something_new"}'],
    'server error' => [500, '{"error":"invalid_request"}'],
]);
