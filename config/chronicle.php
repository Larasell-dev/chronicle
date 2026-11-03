<?php

use Larasell\Chronicle\WideEvents\Outputs\ChronicleFormatter;

return [
    'enabled' => env('CHRONICLE_ENABLED', true),

    /*
     * The channel below that Chronicle writes every event to.
     */
    'default' => env('CHRONICLE_CHANNEL', 'file'),

    /*
     * The channels Chronicle can write to. They work like the channels in
     * config/logging.php and accept the same drivers and options. Chronicle
     * also registers a posthog driver. A stack lists channels from this file.
     *
     * Chronicle sends nothing to PostHog by default. To send events there,
     * uncomment the posthog and stack channels, set CHRONICLE_CHANNEL=stack
     * and POSTHOG_API_KEY in your .env file.
     */
    'channels' => [
        'file' => [
            'driver' => 'single',
            'path' => storage_path('logs/chronicle.log'),
            'formatter' => ChronicleFormatter::class,
            'level' => 'info',
        ],

        // 'posthog' => [
        //     'driver' => 'posthog',
        //     'api_key' => env('POSTHOG_API_KEY'),
        //     'host' => env('POSTHOG_HOST', 'https://us.i.posthog.com'),
        //     'level' => 'info',
        // ],

        // 'stack' => [
        //     'driver' => 'stack',
        //     'channels' => ['file', 'posthog'],
        //     'ignore_exceptions' => false,
        // ],
    ],

    /*
     * The class that decides which request events Chronicle keeps, e.g.
     * App\Logging\KeepFailuresAndSlowRequests::class. It must implement
     * Larasell\Chronicle\WideEvents\Requests\Sampler. Leave it null to keep
     * every request event.
     */
    'sampling' => null,
];
