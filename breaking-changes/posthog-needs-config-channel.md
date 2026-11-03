# Sending events to PostHog needs a channel in `config/chronicle.php`

Chronicle no longer turns PostHog on from an environment variable alone. The shipped config
defines only the `file` channel, so an app sends events to PostHog only when it adds a `posthog`
channel to its own `config/chronicle.php` and also sets the environment variables. This keeps
personal data from reaching a third party by accident.

## Who is affected

Apps that set `CHRONICLE_POSTHOG_ENABLED=true` to send events to PostHog. The variable and the
`chronicle.posthog` config key are removed. Apps that only write to the log file are not affected.

## How to upgrade

Publish the config file:

```bash
php artisan vendor:publish --tag=chronicle.config
```

In `config/chronicle.php`, uncomment the `posthog` and `stack` channels:

```php
'channels' => [
    'file' => [
        // ...
    ],

    'posthog' => [
        'driver' => 'posthog',
        'api_key' => env('POSTHOG_API_KEY'),
        'host' => env('POSTHOG_HOST', 'https://us.i.posthog.com'),
        'level' => 'info',
    ],

    'stack' => [
        'driver' => 'stack',
        'channels' => ['file', 'posthog'],
        'ignore_exceptions' => false,
    ],
],
```

Then replace the old variable in your `.env` file:

```ini
# Before
CHRONICLE_POSTHOG_ENABLED=true
POSTHOG_API_KEY=phc_...

# After
CHRONICLE_CHANNEL=stack
POSTHOG_API_KEY=phc_...
```
