# The `posthog` channel sends batches of OTLP JSON, without the OpenTelemetry packages

Chronicle used the OpenTelemetry SDK to send each event to PostHog in its own blocking HTTP
request, with the SDK's defaults of a 10 second timeout and 3 retries. A slow PostHog could hold
up every request of the app. The SDK also pulled in 11 packages, two of them Composer plugins
every app had to allow.

The `posthog` channel now sends OTLP JSON, the format PostHog's own SDKs use, with Laravel's HTTP
client. It collects a request's events and sends them in one request when the app terminates,
which is after the response under PHP-FPM, after each queued job, and early once a batch holds
100 events or 1 MB. Each batch gets a 1 second connect timeout, a 2 second timeout and one retry,
at most about 4 seconds. A batch PostHog doesn't accept is dropped and reported to PHP's error
log, never thrown.

## Who is affected

- Apps that bound `OpenTelemetry\SDK\Logs\LoggerProviderInterface` in the container so Chronicle
  would send through their own OpenTelemetry pipeline. Chronicle no longer reads that binding.
- Apps that use the OpenTelemetry packages themselves but never required them, because Chronicle
  installed them. Require them in your own `composer.json`.
- Apps whose `composer.json` allows the `php-http/discovery` and `tbachert/spi` plugins only for
  Chronicle. You can remove those entries.

Logs in PostHog now carry your `APP_NAME` as `service.name`, instead of `unknown_service:php`.
Saved PostHog filters on the old service name need updating.

Apps that only set `POSTHOG_API_KEY` and `POSTHOG_HOST` need no changes.

## How to upgrade

If your app uses OpenTelemetry itself, require it directly:

```bash
composer require open-telemetry/api open-telemetry/sdk open-telemetry/exporter-otlp
```

If you sent Chronicle's events through your own OpenTelemetry pipeline, there is no replacement
for the container binding. Either let the `posthog` channel send to PostHog directly, or have your
collector read the JSON lines the `file` channel writes to `storage/logs/chronicle.log`.
