<?php

declare(strict_types=1);

use Larasell\Chronicle\WideEvents\Outputs\ChronicleFormatter;
use Larasell\Chronicle\WideEvents\Outputs\PostHogHandler;

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
     */
    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['file', 'posthog'],
            'ignore_exceptions' => false,
        ],

        'file' => [
            'driver' => 'single',
            'path' => storage_path('logs/chronicle.log'),
            'formatter' => ChronicleFormatter::class,
            'level' => 'info',
        ],

        'posthog' => [
            'driver' => 'posthog',
            'api_key' => env('POSTHOG_API_KEY'),
            'host' => env('POSTHOG_HOST', PostHogHandler::HOST_DEFAULT),
            'level' => PostHogHandler::LEVEL_DEFAULT,
        ],
    ],

    /*
     * The class that decides which request events Chronicle keeps, e.g.
     * App\Logging\KeepFailuresAndSlowRequests::class. It must implement
     * Larasell\Chronicle\WideEvents\Requests\Sampler. Leave it null to keep
     * every request event.
     */
    'sampler' => null,

    /*
     * The keys whose values Chronicle logs as "[redacted]", wherever they
     * are in an event. A key matches when it contains one of these
     * as whole words, in any case: token matches access_token, X-Csrf-Token
     * and apiToken, but not tokens.
     */
    'redact' => [
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey',
        'authorization', 'cookie', 'credential', 'credentials', 'private_key',
        'signature', 'sig', 'card_number', 'cvc', 'cvv', 'iban',
    ],

    /*
     * The class that decides what to log of every value after that, e.g. to
     * mask emails. It must implement Larasell\Chronicle\WideEvents\Redactor.
     * Leave it null to log values as they are.
     */
    'redactor' => null,

    /*
     * Whether Chronicle records an event of type job for every attempt of a
     * queued job, with the fields Chronicle::add() collects while it runs.
     */
    'log_jobs' => env('CHRONICLE_LOG_JOBS', false),

    /*
     * Whether request events log the client's ip. When off, ip is null.
     */
    'log_ip' => env('CHRONICLE_LOG_IP', true),
];
