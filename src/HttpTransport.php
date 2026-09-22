<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel;

use Cbox\Sync\Client\Laravel\Contracts\SyncHeaders;
use Cbox\Sync\Client\Laravel\Contracts\SyncTransport;
use Cbox\Sync\Client\Laravel\ValueObjects\SyncResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

class HttpTransport implements SyncTransport
{
    /** @param array<string, string>|SyncHeaders $headers fixed, or asked for on every request */
    public function __construct(
        private readonly Factory $http,
        private readonly string $baseUrl,
        private readonly array|SyncHeaders $headers = [],
        private readonly int $timeoutSeconds = 30,
    ) {}

    public function post(string $endpoint, array $body): SyncResponse
    {
        try {
            return $this->send($endpoint, $body);
        } catch (ConnectionException) {
            // The request never got an answer: DNS, a refused connection, a
            // timeout. SyncClient already knows what to do with a response that
            // is not a protocol answer - leave the queue exactly as it is and
            // come back - and that is the correct handling here too. Letting it
            // escape instead made the one shipped transport the only one that
            // could throw, and nothing in the client caught it, so a dropped
            // network became an uncaught exception in the host's scheduler.
            return new SyncResponse(0, new \stdClass);
        }
    }

    /** @param array<string, mixed> $body */
    private function send(string $endpoint, array $body): SyncResponse
    {
        $response = $this->http
            ->withHeaders(($this->headers instanceof SyncHeaders ? $this->headers->headers() : $this->headers) + ['Accept' => 'application/json'])
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

        return new SyncResponse($response->status(), $decoded instanceof \stdClass ? $decoded : new \stdClass, self::retryAfter($response->header('Retry-After')));
    }

    /** Seconds, whichever form the header took: a number, or an HTTP date. */
    private static function retryAfter(string $header): ?int
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }
        if (ctype_digit($header)) {
            return (int) $header;
        }
        // An HTTP date and nothing else: strtotime() would take "tomorrow".
        $at = \DateTimeImmutable::createFromFormat(DATE_RFC7231, $header);

        return $at === false ? null : max(0, $at->getTimestamp() - time());
    }

    /** @param array<string, mixed> $body */
    private static function encode(array $body): string
    {
        return json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
