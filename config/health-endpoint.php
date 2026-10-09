<?php

return [

    // Shared secret sent by the monitor in the X-Health-Token header.
    // With no token, both routes return 404.
    'token' => env('HEALTH_TOKEN'),

    // The "app" field in responses. Defaults to a slug of config('app.name').
    'app' => env('HEALTH_APP'),

    // Route path. The errors route is "<path>/errors". Keep it outside api/*.
    'path' => env('HEALTH_PATH', 'health'),

    // Comma-separated queue names on the default queue connection, with an
    // optional "connection:queue" form for others: "default,sync,redis:reports".
    // Every entry is a queue name, never a driver.
    'queues' => env('HEALTH_QUEUES', 'default'),

    // How long the /health body is cached. 0 switches caching off.
    'cache_seconds' => (int) env('HEALTH_CACHE_SECONDS', 10),

    'errors' => [

        // Record errors and serve the errors route.
        'enabled' => (bool) env('HEALTH_ERRORS_ENABLED', true),

        // Lowest log level recorded.
        'level' => env('HEALTH_ERRORS_LEVEL', 'error'),

        // Database connection for the occurrences table. Null is the app default.
        'connection' => env('HEALTH_ERRORS_CONNECTION'),

        // Occurrences older than this are deleted. Never less than 24.
        'keep_hours' => (int) env('HEALTH_ERRORS_KEEP_HOURS', 48),

        // Storm guard: occurrences written per minute. 0 switches the guard off.
        'max_per_minute' => (int) env('HEALTH_ERRORS_MAX_PER_MINUTE', 120),

    ],

];
