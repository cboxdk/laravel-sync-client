<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\Tests\Fixtures;

use Cbox\Sync\Client\Laravel\Contracts\SyncTransport;
use Cbox\Sync\Client\Laravel\ValueObjects\SyncResponse;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/**
 * Sends through the application's own HTTP kernel: real routing, real
 * middleware, real controllers. Only the socket is missing, and the socket is
 * the least interesting part of the contract.
 */
class KernelTransport implements SyncTransport
{
    public function __construct(private readonly Application $app, private readonly string $principal) {}

    public function post(string $endpoint, array $body): SyncResponse
    {
        $request = Request::create('/sync/'.$endpoint, 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X-Test-Principal' => $this->principal,
        ], json_encode($body, JSON_THROW_ON_ERROR));

        $response = $this->app->make(Kernel::class)->handle($request);
        $decoded = json_decode((string) $response->getContent(), true);

        return new SyncResponse($response->getStatusCode(), is_array($decoded) ? $decoded : []);
    }
}
