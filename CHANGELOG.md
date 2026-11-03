# Changelog

## [0.3.0](https://github.com/Larasell-dev/chronicle/compare/v0.2.7...v0.3.0) (2026-11-03)


### ⚠ BREAKING CHANGES

* CHRONICLE_POSTHOG_ENABLED and the chronicle.posthog config are removed. Set CHRONICLE_CHANNEL=stack, or posthog, to send events to PostHog. CHRONICLE_CHANNEL now names a channel in config/chronicle.php, not in config/logging.php. The chronicle log driver and the logging.channels.chronicle, chronicle.file, and posthog channels are removed.
* rename structured errors to structured exceptions
* record() now flattens nested fields and adds the Context fields. Chronicle::flattenContext() is removed.
* ChronicleException and ChronicleException::make() are removed. Catch InvalidSampler or MissingPostHogApiKey instead.
* classes move namespace, e.g. Larasell\Chronicle\Http\Middleware\LogRequests is now Larasell\Chronicle\WideEvents\Requests\Middleware\LogRequests and Larasell\Chronicle\StructuredError is now Larasell\Chronicle\Errors\StructuredError. Chronicle::errorPayload() is removed.
* CHRONICLE_SAMPLING is no longer read. Set 'sampling' in config/chronicle.php instead.

### Features

* add structured versions of the SPL exceptions to throw inline ([7d4fafc](https://github.com/Larasell-dev/chronicle/commit/7d4fafcc1c9094a90273d41ef1e5a719483a24da))
* assign request ids at the start of each request and fall back to the uri for unnamed routes ([70dd527](https://github.com/Larasell-dev/chronicle/commit/70dd52711ba795fcac7b2f761e0f72c6c9ec90fd))
* build every wide event the same way ([67867a3](https://github.com/Larasell-dev/chronicle/commit/67867a38ec8335bf75bd7b3afad787dc6cab3c6b))
* configure chronicle's log channels like config/logging.php ([94db0cc](https://github.com/Larasell-dev/chronicle/commit/94db0cccfa2869e14ca4af3af00347f868db9247))
* install the opentelemetry packages with chronicle ([f0aff78](https://github.com/Larasell-dev/chronicle/commit/f0aff78dbde56022c45d549df1f969d9f5b6ff99))
* set the sampler in the config file only ([ab63275](https://github.com/Larasell-dev/chronicle/commit/ab63275f853a9f29c23336826c5a2cd029c21843))


### Bug Fixes

* require php 8.4 ([97d5d96](https://github.com/Larasell-dev/chronicle/commit/97d5d969cccc6c65bdd048863952b0b90f016371))


### Miscellaneous Chores

* add Amp orb setup script ([4fecb22](https://github.com/Larasell-dev/chronicle/commit/4fecb2278d5b30f9aa7c6cb0f2ae7a1698215bc7))


### Code Refactoring

* organise the package around wide events and structured errors ([fafefd1](https://github.com/Larasell-dev/chronicle/commit/fafefd1b0554e5599230e18319daafc9fb1cbb29))
* rename structured errors to structured exceptions ([402c8e5](https://github.com/Larasell-dev/chronicle/commit/402c8e523f7f88f1b223dc7231f83bf24cb813a8))
* replace ChronicleException with named structured errors ([e4b525c](https://github.com/Larasell-dev/chronicle/commit/e4b525c07c647b09e24d0b3f159c67e8a8081ae3))

### Upgrade guide

#### `ChronicleException` is removed

`ChronicleException::make()` built a structured exception without a class of its own. Chronicle
now has a structured version of each SPL exception for that, such as
`StructuredRuntimeException`, so a thrown exception keeps a meaningful parent type. Chronicle's
own errors are named classes: `InvalidSampler` and `MissingPostHogApiKey`.

##### Who is affected

Apps that throw `Larasell\Chronicle\ChronicleException` or catch it. Apps that never mention it
are not affected.

##### How to upgrade

Throw the structured exception that matches the error. `ChronicleException` extended
`RuntimeException`, so `StructuredRuntimeException` keeps your `catch` blocks working. It takes
the same arguments as `make()`:

```php
// Before
use Larasell\Chronicle\ChronicleException;

throw ChronicleException::make(
    code: 'billing.declined',
    message: 'Payment failed',
    why: 'The issuer declined the charge',
);

// After
use Larasell\Chronicle\Exceptions\StructuredRuntimeException;

throw new StructuredRuntimeException(
    code: 'billing.declined',
    message: 'Payment failed',
    why: 'The issuer declined the charge',
);
```

To catch Chronicle's configuration errors, catch
`Larasell\Chronicle\WideEvents\Requests\Exceptions\InvalidSampler` or
`Larasell\Chronicle\WideEvents\Outputs\Exceptions\MissingPostHogApiKey`. Both extend
`LogicException`.

#### Classes moved to new namespaces

Chronicle's classes are now grouped by its two concepts: wide events in `WideEvents\` and
structured exceptions in `Exceptions\`. The structured error contract is now called a structured
exception, since it is implemented by exceptions.

##### Who is affected

Apps that import any of these classes:

- `Larasell\Chronicle\Http\Middleware\LogRequests` is now
  `Larasell\Chronicle\WideEvents\Requests\Middleware\LogRequests`.
- `Larasell\Chronicle\Sampler` is now `Larasell\Chronicle\WideEvents\Requests\Sampler`.
- `Larasell\Chronicle\StructuredError` is now `Larasell\Chronicle\Exceptions\StructuredException`.
- `Larasell\Chronicle\HasStructuredError` is now
  `Larasell\Chronicle\Exceptions\HasStructuredException`.

`RequestLogger`, `ChronicleFormatter` and `PostHogHandler` moved too, and are now marked
`@internal`. Use the `LogRequests` middleware and the `chronicle` and `posthog` log drivers
instead of these classes. `Chronicle::errorPayload()` is removed.

##### How to upgrade

Update the imports, and rename `StructuredError` and `HasStructuredError` where your exceptions
use them. The trait's methods, such as `structured()` and `errorCode()`, are unchanged.

```php
// Before
use Larasell\Chronicle\HasStructuredError;
use Larasell\Chronicle\StructuredError;

final class PaymentDeclined extends Exception implements StructuredError
{
    use HasStructuredError;
}

// After
use Larasell\Chronicle\Exceptions\HasStructuredException;
use Larasell\Chronicle\Exceptions\StructuredException;

final class PaymentDeclined extends Exception implements StructuredException
{
    use HasStructuredException;
}
```

```php
// Before
use Larasell\Chronicle\Http\Middleware\LogRequests;
use Larasell\Chronicle\Sampler;

// After
use Larasell\Chronicle\WideEvents\Requests\Middleware\LogRequests;
use Larasell\Chronicle\WideEvents\Requests\Sampler;
```

#### `record()` flattens fields and adds `Context`

`record()` now builds its event like request and error events do. It flattens nested arrays into
dotted keys and adds every `Context` field, so your own events carry the same fields as the
requests they happen in.

##### Who is affected

Apps that call `Chronicle::record()` with nested fields, or that read those events and expect
nested objects or no `Context` fields. Apps that call `Chronicle::flattenContext()`, which is
removed. Apps that only use `LogRequests` and `recordError()` are not affected.

##### How to upgrade

Read nested fields by their dotted key. A field you pass still wins over a `Context` field with
the same key.

```php
Context::add('user', ['id' => 7]);

app(Chronicle::class)->record('order.paid', ['order' => ['id' => 42]]);
```

```json
// Before
{"timestamp": "...", "type": "order.paid", "order": {"id": 42}}

// After
{"timestamp": "...", "type": "order.paid", "user.id": 7, "order.id": 42}
```

Instead of calling `flattenContext()` before `record()`, pass the nested array to `record()`.

#### Request ids are assigned when the request starts

`LogRequests` now gives each request its id before the app handles it, instead of when it writes
the request event. The id is stored in `Context` as `request.id`, so every log line of the
request carries it, and is sent back in the `X-Request-Id` response header. The id is the
client's `X-Request-Id` when it is at most 200 visible ASCII characters, and otherwise a UUIDv7
instead of 16 random hex characters.

##### Who is affected

Apps that use `LogRequests` on some routes only, or after other middleware, because log lines
written before `LogRequests` runs have no `request.id`. Apps that parse `request.id` and expect
16 hex characters. With `CHRONICLE_ENABLED=false`, requests get no id and no header.

##### How to upgrade

Prepend `LogRequests` to the global middleware in `bootstrap/app.php`, and remove it from your
routes, so it runs once and before anything else:

```php
use Illuminate\Foundation\Configuration\Middleware;
use Larasell\Chronicle\WideEvents\Requests\Middleware\LogRequests;

// Before
Route::middleware(LogRequests::class)->group(function () {
    // ...
});

// After
->withMiddleware(function (Middleware $middleware): void {
    $middleware->prepend(LogRequests::class);
})
```

To read the id in your own code, call `Larasell\Chronicle\WideEvents\Requests\RequestId::current()`.
It returns `null` outside a request.

#### `CHRONICLE_SAMPLING` is no longer read

A sampler is a class name, which belongs in code that is reviewed and versioned with the class,
not in an environment variable that can name a class the deployed code does not have.

##### Who is affected

Apps that set `CHRONICLE_SAMPLING` in their environment. Without the change, they log every
request again. Apps that set `sampling` in `config/chronicle.php` without `env()` are not
affected.

##### How to upgrade

Publish the config file if you haven't, set the sampler class in it, and remove
`CHRONICLE_SAMPLING` from your environment:

```bash
php artisan vendor:publish --tag=chronicle.config
```

```php
// config/chronicle.php

// Before
'sampling' => env('CHRONICLE_SAMPLING'),

// After
'sampling' => App\Logging\KeepFailuresAndSlowRequests::class,
```
