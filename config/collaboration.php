<?php

declare(strict_types=1);

// Deployment settings. Behaviour is set in code, on `Collaboration`, usually in a service provider.
return [

    // Where the sync route lives, and the middleware it runs behind. It needs the session.
    'path' => env('COLLABORATION_PATH', '_collaboration'),
    'middleware' => ['web'],

    // Where shared drafts and presence are stored. `null` is the default connection.
    'connection' => env('COLLABORATION_CONNECTION'),
    'table_prefix' => env('COLLABORATION_TABLE_PREFIX', 'collaboration_'),

    // Serialises saves. Every application server must share it and it must support locks.
    'cache_store' => env('COLLABORATION_CACHE_STORE'),

    // Keep each draft's head in the cache store, so a poll with nothing new makes no database query.
    // `null` turns it on when the cache store is Redis or Memcached; `true` or `false` decides.
    'draft_cache' => env('COLLABORATION_DRAFT_CACHE'),

    // Where presence is kept: `database`, or `redis` for many servers and many people.
    'presence' => env('COLLABORATION_PRESENCE', 'database'),

    // The Redis connection for `redis` presence, from `config/database.php`. `null` is `default`.
    'redis_connection' => env('COLLABORATION_REDIS_CONNECTION'),

    // Seconds without a heartbeat before someone disappears.
    'presence_timeout' => (int) env('COLLABORATION_PRESENCE_TIMEOUT', 20),

    // A clean draft's edit history is discarded once nobody was seen for this many seconds.
    'compact_after' => (int) env('COLLABORATION_COMPACT_AFTER', 1800),

    // How long an issued room token is valid. Tokens renew themselves while a page is open.
    'token_lifetime' => (int) env('COLLABORATION_TOKEN_LIFETIME', 900),

    // Hours before an image inserted into a draft, but never saved, is deleted daily. `null` turns this off.
    'prune_uploads_after' => env('COLLABORATION_PRUNE_UPLOADS_AFTER', 24),

    // Where images put into rich text while editing are stored, and how large they may be.
    'uploads_disk' => env('COLLABORATION_UPLOADS_DISK', 'public'),
    'uploads_max_kb' => (int) env('COLLABORATION_UPLOADS_MAX_KB', 5120),

    // Sync requests per user per minute, across all of their tabs.
    'rate_limit' => (int) env('COLLABORATION_RATE_LIMIT', 2400),
];
