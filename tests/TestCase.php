<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\Tests;

use Cbox\Sync\Client\Contracts\ClientState;
use Cbox\Sync\Client\Contracts\OutboxStore;
use Cbox\Sync\Client\InMemoryClientState;
use Cbox\Sync\Client\InMemoryOutboxStore;
use Cbox\Sync\Client\Laravel\Contracts\SyncTransport;
use Cbox\Sync\Client\Laravel\SyncClient;
use Cbox\Sync\Client\Laravel\SyncClientServiceProvider;
use Cbox\Sync\Client\Laravel\Tests\Fixtures\HeaderPrincipals;
use Cbox\Sync\Client\Laravel\Tests\Fixtures\KernelTransport;
use Cbox\Sync\Client\Laravel\Tests\Fixtures\NodeType;
use Cbox\Sync\Client\Laravel\Tests\Fixtures\TaskType;
use Cbox\Sync\Client\Laravel\ViewIndex;
use Cbox\Sync\Client\Outbox;
use Cbox\Sync\Laravel\Api\Contracts\SyncPrincipals;
use Cbox\Sync\Laravel\SyncServiceProvider;
use Cbox\Sync\ValueObjects\Replica;
use Cbox\Sync\Views\MultiViewClient;
use Orchestra\Testbench\TestCase as BaseTestCase;

/** One application playing both parts: the server, and a device talking to it. */
class TestCase extends BaseTestCase
{
    protected string $replicaDatabase = '';

    protected function getPackageProviders($app): array
    {
        return [SyncServiceProvider::class, SyncClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->replicaDatabase = tempnam(sys_get_temp_dir(), 'cbox-replica-').'.sqlite';

        $app['config']->set('database.default', 'sync-testing');
        $app['config']->set('database.connections.sync-testing', [
            'driver' => env('SYNC_DRIVER', 'sqlite'),
            'database' => env('SYNC_DATABASE', ':memory:'),
            'host' => env('SYNC_HOST', '127.0.0.1'),
            'port' => env('SYNC_PORT'),
            'username' => env('SYNC_USERNAME', 'root'),
            'password' => env('SYNC_PASSWORD', ''),
            'prefix' => '',
        ]);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('sync.api.enabled', true);
        $app['config']->set('sync.api.middleware', []);
        $app['config']->set('sync.api.types', ['tasks' => TaskType::class, 'nodes' => NodeType::class]);
        $app->bind(SyncPrincipals::class, HeaderPrincipals::class);

        $app['config']->set('sync-client.url', 'http://localhost/sync');
        $app['config']->set('sync-client.replica', 'device-1');
        $app['config']->set('sync-client.database', $this->replicaDatabase);
    }

    /** Binds a transport that talks to this same application, then resolves the client. */
    protected function syncClientAs(string $principal): SyncClient
    {
        $this->app->bind(
            SyncTransport::class,
            fn (): SyncTransport => new KernelTransport($this->app, $principal),
        );
        $this->app->forgetInstance(SyncClient::class);

        return $this->app->make(SyncClient::class);
    }

    /** Drops every singleton that holds local state, the way a process restart would. */
    protected function restartDevice(): void
    {
        foreach ([
            SyncClient::class,
            Outbox::class,
            OutboxStore::class,
            ClientState::class,
            MultiViewClient::class,
            \PDO::class.'@sync-client',
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    /**
     * A fully independent second device: its own replica id, its own queue and
     * its own local state, sharing only the server.
     */
    protected function secondDevice(string $principal, string $replicaId): SyncClient
    {
        $index = new ViewIndex(new \PDO('sqlite::memory:'));
        $index->migrate();

        return new SyncClient(
            new KernelTransport($this->app, $principal),
            Outbox::for(new InMemoryOutboxStore, new Replica($replicaId)),
            new MultiViewClient(new InMemoryClientState),
            $index,
        );
    }

    protected function outbox(): Outbox
    {
        return $this->app->make(Outbox::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/vendor/cboxdk/laravel-sync/database/migrations');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->replicaDatabase !== '') {
            @unlink($this->replicaDatabase);
        }
    }
}
