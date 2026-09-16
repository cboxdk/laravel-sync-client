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
            //
            // The body is encoded here rather than handed over as an array,
            // because the default encoding drops a zero fraction: a float 1.0
            // would go out as 1, and a field value is canonical JSON text whose
            // equality is exact, so the server would store a different value
            // than the one this device holds.
            ->withBody(self::encode($body), 'application/json')
            ->post(rtrim($this->baseUrl, '/').'/'.ltrim($endpoint, '/'));

        // Decoded to objects, never associative arrays: json_decode with assoc
        // turns {} into [], which would store an empty object in a field as an
        // empty array - consistently, so nothing downstream would notice.
        $decoded = json_decode($response->body(), false, 512);

        return new SyncResponse($response->status(), $decoded instanceof \stdClass ? $decoded : new \stdClass);
    }

    /** @param array<string, mixed> $body */
    private static function encode(array $body): string
    {
        return json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
