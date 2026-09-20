<?php

declare(strict_types=1);

use Cbox\Sync\Client\Laravel\Contracts\SyncTransport;
use Cbox\Sync\Client\Laravel\Tests\Fixtures\KernelTransport;
use Cbox\Sync\Client\Laravel\ValueObjects\SyncResponse;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\ValueObjects\EntityKey;

/**
 * A bootstrap that is cut off mid-way - the app is backgrounded, the network
 * drops - has to be resumable. Starting over is not an alternative: the
 * replica refuses a fresh first page once it has applied one.
 */
it('resumes an interrupted bootstrap instead of starting a new one', function () {
    foreach (['n1', 'n2', 'n3'] as $index => $id) {
        $this->outbox()->queue($this->named(new EntityKey('team-1', 'nodes', $id)), MutationKind::Create, [
            Op::set('parent_id', 'p1'), Op::set('name', 'node '.$id),
        ], 0);
    }
    $this->drain($this->syncClientAs('alice'), 'nodes', 'p1');

    // Page one only, then the process dies.
    $this->bindTransport(fn (): SyncTransport => new class($this->app) implements SyncTransport
    {
        private int $calls = 0;

        public function __construct(private $app) {}

        public function post(string $endpoint, array $body): SyncResponse
        {
            if ($endpoint === 'bootstrap' && ++$this->calls > 1) {
                throw new RuntimeException('connection lost');
            }

            return (new KernelTransport($this->app, 'alice'))->post($endpoint, $body);
        }
    });

    try {
        $this->syncClient()->pull('nodes', 'p1', pageSize: 1);
        throw new LogicException('expected the connection to drop');
    } catch (RuntimeException $dropped) {
        expect($dropped->getMessage())->toBe('connection lost');
    }

    // A new process over the same local database, and a working connection.
    $this->restartDevice();
    $resumed = $this->syncClientAs('alice');
    $resumed->pull('nodes', 'p1', pageSize: 1);

    foreach (['n1', 'n2', 'n3'] as $id) {
        expect($resumed->replica()->record($this->named(new EntityKey('team-1', 'nodes', $id))))->not->toBeNull();
    }
});
