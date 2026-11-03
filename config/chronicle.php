<?php

return [
    'enabled' => env('CHRONICLE_ENABLED', true),

    /*
     * The Laravel log channel Chronicle writes to. Defaults to the
     * `chronicle` stack (file + PostHog). The package registers the
     * `chronicle` (file) and `posthog` drivers, so you can compose
     * your own stack in config/logging.php.
     */
    'channel' => env('CHRONICLE_CHANNEL', 'chronicle'),

    'posthog' => [
        'enabled' => env('CHRONICLE_POSTHOG_ENABLED', false),
        'api_key' => env('POSTHOG_API_KEY'),
        'host' => env('POSTHOG_HOST', 'https://us.i.posthog.com'),
    ],

    /*
     * The class that decides which request events Chronicle keeps, e.g.
     * App\Logging\KeepFailuresAndSlowRequests::class. It must implement
     * Larasell\Chronicle\WideEvents\Requests\Sampler. Leave it null to keep
     * every request event.
     */
    'sampling' => null,
];
