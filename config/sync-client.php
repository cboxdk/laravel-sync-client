<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Server
    |--------------------------------------------------------------------------
    |
    | Where this device syncs to, and how it authenticates. The headers are
    | whatever the server's own middleware expects; this package has no opinion.
    |
    */

    'url' => env('SYNC_CLIENT_URL'),

    'headers' => [],

    'timeout' => (int) env('SYNC_CLIENT_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | This device
    |--------------------------------------------------------------------------
    |
    | The replica id identifies this device's mutation stream and must be
    | STABLE for the life of its local database. Changing it strands every
    | mutation still queued under the old one.
    |
    | The local database holds the replica's own state and its outbox, and it
    | has to be durable: a device that forgets what it queued cannot produce a
    | stream the server will accept without being told where to resume.
    |
    */

    'replica' => env('SYNC_CLIENT_REPLICA'),

    'database' => env('SYNC_CLIENT_DATABASE', storage_path('sync/replica.sqlite')),

    'page_size' => (int) env('SYNC_CLIENT_PAGE_SIZE', 100),

];
