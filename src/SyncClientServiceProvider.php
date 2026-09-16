<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel;

use Cbox\Sync\Client\Contracts\ClientState;
use Cbox\Sync\Client\Contracts\OutboxStore;
use Cbox\Sync\Client\Laravel\Contracts\SyncTransport;
use Cbox\Sync\Client\Outbox;
use Cbox\Sync\Client\Pdo\PdoClientState;
use Cbox\Sync\Client\Pdo\PdoOutboxStore;
use Cbox\Sync\ValueObjects\Replica;
use Cbox\Sync\Views\MultiViewClient;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

class SyncClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sync-client.php', 'sync-client');

        // One connection for both, so the replica's state and the queue that
        // feeds it commit against the same database rather than drifting apart
        // if the process dies between them.
        $this->app->singleton(\PDO::class.'@sync-client', function (Application $app): \PDO {
            $path = $this->text($app, 'sync-client.database', '');
            if ($path === '') {
                throw new \RuntimeException('sync-client.database must point at a writable SQLite file.');
            }
            $directory = dirname($path);
            if (! is_dir($directory)) {
                mkdir($directory, 0o775, true);
            }

            return new \PDO('sqlite:'.$path);
        });

        $this->app->singleton(ClientState::class, function (Application $app): ClientState {
            $state = new PdoClientState($this->pdo($app));
            $state->migrate();

            return $state;
        });

        $this->app->singleton(OutboxStore::class, function (Application $app): OutboxStore {
            $store = new PdoOutboxStore($this->pdo($app));
            $store->migrate();

            return $store;
        });

        $this->app->singleton(MultiViewClient::class, fn (Application $app): MultiViewClient => new MultiViewClient($app->make(ClientState::class)));

        $this->app->singleton(Outbox::class, fn (Application $app): Outbox => Outbox::for(
            $app->make(OutboxStore::class),
            new Replica($this->text($app, 'sync-client.replica', '') ?: throw new \RuntimeException('sync-client.replica must be set and stable for this device.')),
        ));

        $this->app->bindIf(SyncTransport::class, fn (Application $app): SyncTransport => new HttpTransport(
            $app->make(Factory::class),
            $this->text($app, 'sync-client.url', '') ?: throw new \RuntimeException('sync-client.url must point at the sync server.'),
            $this->headers($app),
            $this->number($app, 'sync-client.timeout', 30),
        ));

        $this->app->singleton(ViewIndex::class, function (Application $app): ViewIndex {
            $index = new ViewIndex($this->pdo($app));
            $index->migrate();

            return $index;
        });

        $this->app->singleton(SyncClient::class, fn (Application $app): SyncClient => new SyncClient(
            $app->make(SyncTransport::class),
            $app->make(Outbox::class),
            $app->make(MultiViewClient::class),
            $app->make(ViewIndex::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/sync-client.php' => $this->app->configPath('sync-client.php')], 'sync-client-config');
        }
    }

    /** The container is untyped, so the one place that resolves the handle narrows it. */
    private function pdo(Application $app): \PDO
    {
        $pdo = $app->make(\PDO::class.'@sync-client');

        return $pdo instanceof \PDO ? $pdo : throw new \RuntimeException('The sync client connection is not a PDO handle.');
    }

    private function text(Application $app, string $key, string $fallback): string
    {
        $value = $app->make(Repository::class)->get($key);

        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    private function number(Application $app, string $key, int $fallback): int
    {
        $value = $app->make(Repository::class)->get($key);

        return is_int($value) && $value > 0 ? $value : $fallback;
    }

    /** @return array<string, string> */
    private function headers(Application $app): array
    {
        $configured = $app->make(Repository::class)->get('sync-client.headers');
        if (! is_array($configured)) {
            return [];
        }
        $headers = [];
        foreach ($configured as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
