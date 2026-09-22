<?php

declare(strict_types=1);

use Cbox\Sync\Client\Laravel\Contracts\SyncTransport;
use Cbox\Sync\Client\Laravel\ValueObjects\SyncResponse;
use Cbox\Sync\Contracts\EntityValidator;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Data\ValidationContext;
use Cbox\Sync\Data\ValidationFailure;
use Cbox\Sync\Data\ValidationResult;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\Laravel\Api\SyncService;
use Illuminate\Support\Facades\Http;

/** The server refuses any record titled "invalid". */
function refusingInvalidTitles(object $test): void
{
    app()->instance(EntityValidator::class, new class implements EntityValidator
    {
        public function validate(ValidationContext $context): ValidationResult
        {
            return $context->proposed->value('title')->value() === 'invalid'
                ? new ValidationResult([new ValidationFailure('invalid_title', 'no', 'title')])
                : new ValidationResult;
        }
    });
    foreach ([Engine::class, SyncService::class] as $service) {
        app()->forgetInstance($service);
    }
}

/**
 * A refused write was reported only in the push's return value. A pull that
 * failed after it, or the process ending, lost that report - and the write,
 * already gone from the queue, was never mentioned again.
 */
it('keeps a refused write until the application dismisses it, whatever happens after', function () {
    refusingInvalidTitles($this);
    $client = $this->syncClientAs('alice');
    $client->outbox()->queue($client->key('tasks', 'team-1', 't1'), MutationKind::Create, [Op::set('title', 'invalid'), Op::set('status', 'open')], 0);

    $client->push('tasks', 'team-1');
    $this->restartDevice();
    $restarted = $this->syncClientAs('alice');

    expect(array_column($restarted->outbox()->abandoned(), 'reason'))->toBe(['validation_failed']);
});

/** A child of a create the server processed and refused was sent anyway, pointing at a record that never existed. */
it('holds back a child whose parent the server refused', function () {
    refusingInvalidTitles($this);
    config()->set('sync-client.references', ['nodes' => ['name' => 'tasks']]);
    $client = $this->syncClientAs('alice');
    $client->outbox()->queue($client->key('tasks', 'team-1', 'P'), MutationKind::Create, [Op::set('title', 'invalid'), Op::set('status', 'open')], 0);
    $client->outbox()->queue($client->key('nodes', 'p1', 'K'), MutationKind::Create, [Op::set('name', 'P'), Op::set('parent_id', 'p1')], 0);

    $client->push('nodes', 'p1');

    expect(array_column($client->outbox()->abandoned(), 'reason'))->toBe(['validation_failed', 'parent_abandoned'])
        ->and($client->outbox()->pending())->toBe(0);
});

/** Dismissing a refused parent released its children, still holding its handle. */
it('takes the writes that need a dismissed create with it', function () {
    config()->set('sync-client.references', ['nodes' => ['name' => 'tasks']]);
    $reader = $this->syncClientAs('reader');
    $reader->outbox()->queue($reader->key('tasks', 'team-1', 'P'), MutationKind::Create, [Op::set('title', 'parent'), Op::set('status', 'open')], 0);
    $child = $reader->outbox()->queue($reader->key('nodes', 'p1', 'K'), MutationKind::Create, [Op::set('name', 'P'), Op::set('parent_id', 'p1')], 0);
    $reader->push('tasks', 'team-1');
    $parent = $reader->outbox()->abandoned()[0]['mutation'];

    $reader->dismiss($parent->id);

    expect($reader->outbox()->abandoned())->toHaveCount(1)
        ->and($reader->outbox()->abandoned()[0]['mutation']->id)->toBe($child->id)
        ->and($reader->outbox()->abandoned()[0]['reason'])->toBe('parent_abandoned');
});

/** A write that may already be on the server is not sent again under a new identity by a "retry all" button. */
it('refuses to requeue a write that may have landed unless told it has been checked', function () {
    $this->bindTransport(fn (): SyncTransport => new class implements SyncTransport
    {
        public function post(string $endpoint, array $body): SyncResponse
        {
            return new SyncResponse(200, (object) ['status' => 'receipt_pruned', 'acknowledged_sequence' => 1, 'reason' => 'receipt_pruned']);
        }
    });
    $this->queueTask('t1');
    $client = $this->syncClient();
    $client->push('tasks', 'team-1');
    $id = $client->outbox()->abandoned()[0]['mutation']->id;

    expect(fn () => $client->requeue($id))->toThrow(InvalidRequest::class)
        ->and($client->requeue($id, evenIfItMayHaveLanded: true))->not->toBeNull();
});

/** Laravel's auth middleware answers 401 with no error code, and a pull took that for "no answer" and reported all clear. */
it('reports an expired session on the pull too', function () {
    $this->bindTransport(fn (): SyncTransport => new class implements SyncTransport
    {
        public function post(string $endpoint, array $body): SyncResponse
        {
            return new SyncResponse(401, (object) ['message' => 'Unauthenticated.']);
        }
    });

    $outcome = $this->syncClient()->sync('tasks', 'team-1');

    expect($outcome->unauthenticated)->toBeTrue()
        ->and($outcome->pullFailure?->errorCode)->toBe('unauthenticated');
});

/** One write too large for a proxy blocked its whole queue for ever, with nothing to say which or why. */
it('abandons a write too large to send, and names the write a queue is waiting on', function () {
    $this->bindTransport(fn (): SyncTransport => new class implements SyncTransport
    {
        private int $calls = 0;

        public function post(string $endpoint, array $body): SyncResponse
        {
            // A proxy refuses the first write for its size, in HTML; then the server is busy.
            return ++$this->calls === 1 ? new SyncResponse(413, new stdClass) : new SyncResponse(503, (object) ['error' => 'retry', 'retriable' => true], 7);
        }
    });
    $this->queueTask('big');
    $this->queueTask('next');
    $client = $this->syncClient();

    $outcome = $client->push('tasks', 'team-1');

    expect(array_column($client->outbox()->abandoned(), 'reason'))->toBe(['body_too_large'])
        ->and($outcome->abandoned)->toBe(1)
        ->and($outcome->retryLater)->toBeTrue()
        ->and($outcome->blockedBy)->toBe($client->outbox()->peek('tasks', 'team-1')?->id)
        ->and($outcome->httpStatus)->toBe(503)
        ->and($outcome->error)->toBe('retry')
        ->and($outcome->retryAfter)->toBe(7);
});

/** Headers were read once, when the client was built: a token refreshed after sign-in was never sent. */
it('sends the headers as they are now, not as they were at boot', function () {
    Http::fake(['*' => Http::response(['error' => 'retry', 'retriable' => true], 503)]);
    config()->set('sync-client.url', 'https://sync.test');
    config()->set('sync-client.headers', ['Authorization' => 'Bearer old']);
    $transport = app(SyncTransport::class);
    config()->set('sync-client.headers', ['Authorization' => 'Bearer new']);

    $transport->post('push', []);

    Http::assertSent(fn ($request) => $request->header('Authorization') === ['Bearer new']);
});

/** Dismissing through the outbox directly used to release a refused parent's children with its handle. */
it('takes a refused parent\'s children with it when dismissed through the outbox directly', function () {
    config()->set('sync-client.references', ['nodes' => ['name' => 'tasks']]);
    $reader = $this->syncClientAs('reader');
    $reader->outbox()->queue($reader->key('tasks', 'team-1', 'P'), MutationKind::Create, [Op::set('title', 'parent'), Op::set('status', 'open')], 0);
    $reader->outbox()->queue($reader->key('nodes', 'p1', 'K'), MutationKind::Create, [Op::set('name', 'P'), Op::set('parent_id', 'p1')], 0);
    $reader->push('tasks', 'team-1');

    $reader->outbox()->dismiss($reader->outbox()->abandoned()[0]['mutation']->id);

    expect(array_column($reader->outbox()->abandoned(), 'reason'))->toBe(['parent_abandoned']);
});

/** A restored device's whole stream is settled at once, and the outcome counts every write settled, not one. */
it('counts every write a restored stream settles', function () {
    $this->bindTransport(fn (): SyncTransport => new class implements SyncTransport
    {
        public function post(string $endpoint, array $body): SyncResponse
        {
            return new SyncResponse(200, (object) ['status' => 'receipt_pruned', 'acknowledged_sequence' => 10, 'reason' => 'receipt_pruned']);
        }
    });
    foreach (['a', 'b', 'c'] as $id) {
        $this->queueTask($id);
    }

    $outcome = $this->syncClient()->push('tasks', 'team-1');

    expect($outcome->abandoned)->toBe(3)
        ->and($this->outbox()->pending())->toBe(0);
});

/** A pull the server asked to repeat later reported all clear. */
it('says when the pull after a push did not catch up', function () {
    $this->bindTransport(fn (): SyncTransport => new class implements SyncTransport
    {
        public function post(string $endpoint, array $body): SyncResponse
        {
            return new SyncResponse(503, (object) ['error' => 'retry', 'retriable' => true], 2);
        }
    });

    $outcome = $this->syncClient()->sync('tasks', 'team-1');

    expect($outcome->pulled)->toBeFalse()
        ->and($outcome->pullFailure)->toBeNull();
});

it('reads Retry-After as an HTTP date too', function () {
    Http::fake(['*' => Http::response(['error' => 'retry', 'retriable' => true], 503, ['Retry-After' => gmdate('D, d M Y H:i:s', time() + 120).' GMT'])]);
    config()->set('sync-client.url', 'https://sync.test');

    $response = app(SyncTransport::class)->post('push', []);

    expect($response->retryAfter)->toBeGreaterThan(100)->toBeLessThanOrEqual(120);
});

/** A scripted server: each call answers with the next response, the last one repeating. */
function scripted(object $test, SyncResponse ...$answers): void
{
    $test->bindTransport(fn (): SyncTransport => new class($answers) implements SyncTransport
    {
        /** @param list<SyncResponse> $answers */
        public function __construct(private array $answers) {}

        public function post(string $endpoint, array $body): SyncResponse
        {
            return count($this->answers) > 1 ? array_shift($this->answers) : $this->answers[0];
        }
    });
}

/**
 * A busy answer, then a refusal: nothing ever landed, but counting the busy
 * answer as a possible landing turned off the cascade, and the child went out
 * pointing at a record that never existed.
 */
it('takes a refused parent\'s child with it even when the parent was first answered busy', function () {
    config()->set('sync-client.references', ['nodes' => ['name' => 'tasks']]);
    scripted($this,
        new SyncResponse(503, (object) ['error' => 'retry', 'retriable' => true]),
        new SyncResponse(200, (object) ['status' => 'validation_failed', 'record_version' => 0, 'acknowledged_sequence' => 1]),
    );
    $client = $this->syncClient();
    $client->outbox()->queue($client->key('tasks', 'team-1', 'P'), MutationKind::Create, [Op::set('title', 'x')], 0);
    $client->outbox()->queue($client->key('nodes', 'p1', 'K'), MutationKind::Create, [Op::set('name', 'P')], 0);
    $client->push('tasks', 'team-1');
    $client->push('tasks', 'team-1');

    $cascaded = $client->dismiss($client->outbox()->abandoned()[0]['mutation']->id);

    expect($cascaded)->toBe(1)
        ->and(array_column($client->outbox()->abandoned(), 'reason'))->toBe(['parent_abandoned']);
});

/** A session that expired, then a refusal once signed in: the user's later requeue is legitimate, not a possible duplicate. */
it('lets a write refused after an expired session be requeued', function () {
    scripted($this,
        new SyncResponse(401, (object) ['message' => 'Unauthenticated.']),
        new SyncResponse(403, (object) ['error' => 'forbidden', 'message' => 'no', 'retriable' => false]),
    );
    $this->queueTask('t1');
    $client = $this->syncClient();
    $client->push('tasks', 'team-1');
    $client->push('tasks', 'team-1');

    expect($client->requeue($client->outbox()->abandoned()[0]['mutation']->id))->not->toBeNull();
});

it('reads Retry-After strictly', function () {
    Http::fake(['*' => Http::response(['error' => 'retry', 'retriable' => true], 503, ['Retry-After' => 'tomorrow'])]);
    config()->set('sync-client.url', 'https://sync.test');

    expect(app(SyncTransport::class)->post('push', [])->retryAfter)->toBeNull();
});
