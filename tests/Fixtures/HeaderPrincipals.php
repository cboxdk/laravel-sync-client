<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\Tests\Fixtures;

use Cbox\Sync\Laravel\Api\Contracts\SyncPrincipals;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Illuminate\Http\Request;

/** Stands in for the host's guard: whoever the X-Test-Principal header names. */
class HeaderPrincipals implements SyncPrincipals
{
    public function resolve(Request $request): ?SyncPrincipal
    {
        $id = $request->headers->get('X-Test-Principal');

        return is_string($id) && $id !== '' ? new SyncPrincipal($id, $id) : null;
    }
}
