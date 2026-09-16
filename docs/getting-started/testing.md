---
title: "Testing"
weight: 20
description: "Drive the client without a server."
---

# Testing

Bind `Contracts\SyncTransport` to something of your own and the whole client is
testable without a socket:

```php
$this->app->bind(SyncTransport::class, fn () => new class implements SyncTransport {
    public function post(string $endpoint, array $body): SyncResponse
    {
        return new SyncResponse(503, ['error' => 'retry', 'retriable' => true]);
    }
});

expect(app(SyncClient::class)->push('tasks')->retryLater)->toBeTrue();
```

This package's own suite goes further: it runs the client against a real
`cboxdk/laravel-sync` server in the same application, through Laravel's HTTP
kernel. Real routing, real middleware, real controllers, real database — only
the socket is missing, and the socket is the least interesting part of the
contract.
