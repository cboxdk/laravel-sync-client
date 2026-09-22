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

    /*
    |--------------------------------------------------------------------------
    | Conflicts
    |--------------------------------------------------------------------------
    |
    | Null: the server decides, and by default keeps both values for someone to
    | choose between. A RebasePolicy class: this device decides instead. A write
    | that meets a newer edit of the same field is refused, the policy says what
    | it should now be, and it is sent again knowing what it replaces.
    |
    | Shipped: KeepMine (latest deliberate edit wins) and TakeTheirs (first to
    | reach the server wins). Write your own to merge per field.
    |
    */

    'rebase' => null, // \Cbox\Sync\Client\Laravel\Rebase\KeepMine::class

    /*
    |--------------------------------------------------------------------------
    | References
    |--------------------------------------------------------------------------
    |
    | Fields that hold another record's id, per entity type, each naming the
    | type it points at. A record created offline is known by a handle until
    | the server names it; a child queued under it carries that handle. Listed
    | here, a push sends the unsent parent first - from whatever scope it was
    | queued under - and rewrites the field to its real id before the child
    | goes. Unlisted, the child reaches the server pointing at an id that never
    | existed.
    |
    |     'tasks' => ['project_id' => 'projects', 'assignee_id' => 'users'],
    |
    */

    'references' => [],

];
