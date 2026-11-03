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

    /*
     * The global fields of every event, read once at boot and named as
     * OpenTelemetry's resource attributes. The posthog channel sends them
     * as the resource of each batch. Every event also gets
     * process.runtime.version, PHP's version.
     *
     * service:     service.name. Null uses app.name.
     * release:     service.version, e.g. the commit or tag your deploy sets.
     *              Null leaves it out.
     * environment: deployment.environment. Null uses app.env.
     * host:        host.name. Null uses gethostname(); an empty string, as
     *              in CHRONICLE_HOST=, leaves it out.
     */
    'service' => env('CHRONICLE_SERVICE'),
    'release' => env('CHRONICLE_RELEASE'),
    'environment' => env('CHRONICLE_ENVIRONMENT'),
    'host' => env('CHRONICLE_HOST'),

    /*
     * Any other global fields, e.g. ['region' => 'eu'], with values that are
     * strings, numbers or bools. Instead of an array, this takes a string
     * in OpenTelemetry's format, as CHRONICLE_FIELDS="region=eu,team=shop"
     * sets it, with values percent-encoded where they hold a comma. At most
     * 128 global fields, Chronicle's own included.
     */
    'fields' => env('CHRONICLE_FIELDS', []),
];
