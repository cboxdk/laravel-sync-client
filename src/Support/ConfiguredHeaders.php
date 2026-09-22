<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\Support;

use Cbox\Sync\Client\Laravel\Contracts\SyncHeaders;
use Illuminate\Contracts\Config\Repository;

/** sync-client.headers, read on every request, so changing it at runtime takes effect at once. */
class ConfiguredHeaders implements SyncHeaders
{
    public function __construct(private readonly Repository $config) {}

    public function headers(): array
    {
        $configured = $this->config->get('sync-client.headers');
        $headers = [];
        foreach (is_array($configured) ? $configured : [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
