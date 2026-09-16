<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel;

use Cbox\Sync\Client\Laravel\Contracts\SyncTransport;
use Cbox\Sync\Client\Laravel\ValueObjects\SyncResponse;
use Illuminate\Http\Client\Factory;

class HttpTransport implements SyncTransport
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly Factory $http,
        private readonly string $baseUrl,
        private readonly array $headers = [],
        private readonly int $timeoutSeconds = 30,
    ) {}

    public function post(string $endpoint, array $body): SyncResponse
    {
        $response = $this->http
            ->withHeaders($this->headers + ['Accept' => 'application/json'])
            ->timeout($this->timeoutSeconds)
            // No automatic retry here. A retry is only safe when it reuses the
            // same mutation id, and whether that is the right move depends on
            // the answer - which is SyncClient's decision, not the socket's.
            ->asJson()
            ->post(rtrim($this->baseUrl, '/').'/'.ltrim($endpoint, '/'), $body);

        $decoded = $response->json();

        return new SyncResponse($response->status(), is_array($decoded) ? $decoded : []);
    }
}
