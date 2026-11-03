# Chronicle

Chronicle adds two things to your Laravel app: structured exceptions and wide event logging.

Structured exceptions give an exception a stable code, the reason it happened, and a fix, so you
can count and alert on an error by its code, and whoever reads it knows what to do next. Wide
event logging writes one event per unit of work, like a request or a job, that holds everything
your app knows about it. When something goes wrong, you read one event instead of piecing the
story together from scattered log lines.

```json
{"timestamp":"2026-11-02T14:03:12+00:00","type":"request","request.id":"019a48c2-7f3e-7b21-9c4d-2e5f8a1b3c6d","method":"POST","url":"https://shop.test/checkout","route":"checkout.store","status":500,"duration_ms":812.4,"user.id":42,"cart.items":3,"error.code":"billing.card-declined","error.message":"Payment failed: card declined","error.fix":"Try a different card"}
```

Events go to a log file, to [PostHog](https://posthog.com), or both. Read the
[full documentation](https://larasell.dev/docs/packages/chronicle) for every option.

## Installation

Chronicle requires PHP 8.4 and Laravel 12 or 13. You can install the package via Composer:

```bash
composer require larasell-dev/chronicle
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="chronicle.config"
```

This is the contents of the published config file, without its comments:

```php
return [
    'enabled' => env('CHRONICLE_ENABLED', true),
    'default' => env('CHRONICLE_CHANNEL', 'file'),
    'channels' => [
        'stack' => ['driver' => 'stack', 'channels' => ['file', 'posthog'], 'ignore_exceptions' => false],
        'file' => ['driver' => 'single', 'path' => storage_path('logs/chronicle.log'), 'formatter' => ChronicleFormatter::class, 'level' => 'info'],
        'posthog' => ['driver' => 'posthog', 'api_key' => env('POSTHOG_API_KEY'), 'host' => env('POSTHOG_HOST', PostHogHandler::HOST_DEFAULT), 'level' => PostHogHandler::LEVEL_DEFAULT],
    ],
    'sampler' => null,
    'redact' => [
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey',
        'authorization', 'cookie', 'credential', 'credentials', 'private_key',
        'signature', 'sig', 'card_number', 'cvc', 'cvv', 'iban',
    ],
    'redactor' => null,
    'log_jobs' => env('CHRONICLE_LOG_JOBS', false),
    'log_ip' => env('CHRONICLE_LOG_IP', true),
    'service' => env('CHRONICLE_SERVICE'),
    'release' => env('CHRONICLE_RELEASE'),
    'environment' => env('CHRONICLE_ENVIRONMENT'),
    'host' => env('CHRONICLE_HOST'),
    'fields' => env('CHRONICLE_FIELDS', []),
];
```

To send events to PostHog as well as the log file, set two environment variables:

```dotenv
CHRONICLE_CHANNEL=stack
POSTHOG_API_KEY=phc_...
```

## Usage

### Log every request

Prepend the `LogRequests` middleware in `bootstrap/app.php`, so it runs before any other
middleware and every request, a 404 included, gets one event and an `X-Request-Id`:

```php
use Larasell\Chronicle\WideEvents\Requests\Middleware\LogRequests;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->prepend(LogRequests::class);
})
```

Add it once, in your app. Packages shouldn't add it.

### Add fields to the current event

`add()` puts a field on the event of the current request or job, and only on it:

```php
use Larasell\Chronicle\Facades\Chronicle;

Chronicle::add('cart', ['items' => 3, 'total' => 8900]);
```

Fields you put in Laravel's `Context` are added to every event too, and to every log line and
queued job of the request.

### Record your own events

```php
use Larasell\Chronicle\Facades\Chronicle;
use Larasell\Chronicle\WideEvents\Enums\Level;

Chronicle::record('inventory.low', ['sku' => 'SKU-42', 'stock' => 2], Level::Warning);

Chronicle::recordError($exception, ['order.id' => 7]);
```

### Throw structured exceptions

Throw one of the structured SPL exceptions inline:

```php
use Larasell\Chronicle\Exceptions\StructuredRuntimeException;

throw new StructuredRuntimeException(
    errorCode: 'billing.card-declined',
    message: 'Payment failed: card declined',
    why: 'The issuer declined the charge',
    fix: 'Try a different card',
);
```

Or give an error a class of its own:

```php
use Larasell\Chronicle\Exceptions\HasStructuredException;
use Larasell\Chronicle\Exceptions\StructuredException;

final class PaymentDeclined extends Exception implements StructuredException
{
    use HasStructuredException;

    public static function byIssuer(): self
    {
        return self::structured(
            errorCode: 'billing.card-declined',
            message: 'Payment failed: card declined',
            fix: 'Try a different card',
        );
    }
}
```

### Log queued jobs

Set `CHRONICLE_LOG_JOBS=true` to record one `job` event for every attempt of a queued job, with
the fields `add()` collects while it runs.

### Tell services, releases and hosts apart

Every event carries a few global fields, named as OpenTelemetry's resource attributes, so you can
filter events from several apps, deploys or machines:

| Field | Set by | Default |
| --- | --- | --- |
| `service.name` | `CHRONICLE_SERVICE` | `app.name` (`APP_NAME`) |
| `service.version` | `CHRONICLE_RELEASE` | none |
| `deployment.environment` | `CHRONICLE_ENVIRONMENT` | `app.env` (`APP_ENV`) |
| `host.name` | `CHRONICLE_HOST` | `gethostname()`; an empty `CHRONICLE_HOST=` leaves it out |
| `process.runtime.version` | | `PHP_VERSION` |

Set the release in your deploy, e.g. to the commit or tag, and add any other fields of your own
in OpenTelemetry's `key=value,key=value` format, or as an array in `fields` of
`config/chronicle.php`:

```dotenv
CHRONICLE_RELEASE=3f2a9c1
CHRONICLE_FIELDS="region=eu,team=shop"
```

Chronicle reads them once at boot. A malformed setting, e.g. `CHRONICLE_FIELDS="region"` or more
than 128 fields, its own included, throws in debug mode and in the local and testing environments.
Elsewhere Chronicle ignores that setting and warns in PHP's error log, since logging must never take
the app down. They are redacted and bounded like any other field. Fields Chronicle measures, such as
`status` or `route`, win over them, and so do `Context`, `add()` and the fields you pass to
`record()`. The posthog channel sends them once per batch, as the OTLP resource.

### What to log

Chronicle logs what it can measure itself: the request's id, route, status, duration and user, the
error, and the global fields. What makes an event useful is what only your app knows, so add it
with `Chronicle::add()` where your code knows it:

- **Who it was for**, beyond `user.id`: e.g. `user.plan` or `user.account_age_days`.
- **What it worked on**, by id and the numbers that describe it: e.g. `order.id`, `cart.items`,
  `cart.total`.
- **Which code ran**: the feature flags and experiment variants that were on.
- **What came out**: result counts, like `products.total` for a search, and where your own logic
  gave up.
- **What it called**: for each external service, the provider, `duration_ms` and attempts.

Use the same key for the same thing everywhere, and put units last, like `duration_ms`. Leave out
personal data such as names and email addresses, secrets, and request or response bodies. For more,
read [Logging Sucks](https://loggingsucks.com/).

### Redact secrets

Chronicle logs the value of every key that contains a word from `redact` as `[redacted]`,
wherever it is in an event: in fields, `Context`, the query, and route parameters. A token in a
path, as in `/reset-password/{token}`, is logged as the route's template.

### Test what your code records

`Chronicle::fake()` keeps the finished events instead of writing them:

```php
use Larasell\Chronicle\Facades\Chronicle;

it('records the checkout', function () {
    $chronicle = Chronicle::fake();

    $this->post('/checkout');

    $chronicle->assertRecorded('request', fn (array $event) => $event['cart.items'] === 3);
});
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently. Breaking
changes come with an upgrade guide.

## Security Vulnerabilities

Please report security vulnerabilities through
[GitHub Security Advisories](https://github.com/larasell-dev/chronicle/security/advisories/new) or
by email to [nils@larasell.dev](mailto:nils@larasell.dev), not in a public issue.

## Credits

- [Nils Haberkamp](https://github.com/haberkamp)
- [All Contributors](../../contributors)

## About us

Chronicle is built by [Larasell](https://larasell.dev), an e-commerce framework for Laravel
developers. Larasell lets you own the full application and customise everything from business
logic to the storefront. Chronicle is the logging package Larasell and its packages use, and it
works in any Laravel app.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
