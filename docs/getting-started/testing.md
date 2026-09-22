---
title: "Testing"
weight: 20
description: "Drive the client without a server."
---

# Testing

Bind `Contracts\SyncTransport` to something of your own and the whole client is
testable without a socket:

```php
use Cbox\Sync\Client\Laravel\Contracts\SyncTransport;
use Cbox\Sync\Client\Laravel\SyncClient;
use Cbox\Sync\Client\Laravel\ValueObjects\SyncResponse;
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Enums\MutationKind;

$this->app->bind(SyncTransport::class, fn () => new class implements SyncTransport {
    public function post(string $endpoint, array $body): SyncResponse
    {
        return new SyncResponse(503, (object) ['error' => 'retry', 'retriable' => true]);
    }
});

$client = app(SyncClient::class);
$client->outbox()->queue($client->key('tasks', 'team-1', 'my-handle'), MutationKind::Create, [FieldOperation::set('title', 'x')], 0);

expect($client->push('tasks', 'team-1')->retryLater)->toBeTrue();
```

This package's own suite goes further: it runs the client against a real
`cboxdk/laravel-sync` server in the same application, through Laravel's HTTP
kernel. Real routing, real middleware, real controllers, real database — only
the socket is missing, and the socket is the least interesting part of the
contract.
