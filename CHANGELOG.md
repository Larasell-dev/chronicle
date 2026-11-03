# Changelog

## [0.4.0](https://github.com/Larasell-dev/chronicle/compare/v0.3.0...v0.4.0) (2026-11-03)


### ⚠ BREAKING CHANGES

* log a query exception's SQL without its bindings
* log the query and path values as redacted fields
* redact values of secret keys in every event
* keep the request fields Chronicle measured over Context
* name the structured error code argument errorCode
* make the error fields of structured exceptions private
* flatten objects through their JSON form, like arrays
* rename the sampling config to sampler
* register Chronicle's channels once at boot
* replace values JSON can't encode instead of losing the event
* bound every event in depth, field count and bytes
* move Chronicle into the WideEvents namespace
* take recordError arguments in record's order, with own Level
* send PostHog events in batches, without OpenTelemetry

### Features

* add a facade with a fake and scoped events ([3a7f656](https://github.com/Larasell-dev/chronicle/commit/3a7f656bedfdb320fe1fc0e52db3e859fcc09258))
* add fields to the current request's event with add() ([c9fa94d](https://github.com/Larasell-dev/chronicle/commit/c9fa94d12d8aaa17828bf19feb2a769edfb6d68e))
* assert that redaction left no secret behind ([fb91e10](https://github.com/Larasell-dev/chronicle/commit/fb91e10b6584fb80a6f33b1a34d1ef46cd26d69d))
* bound every event in depth, field count and bytes ([14fdf0f](https://github.com/Larasell-dev/chronicle/commit/14fdf0f5adfd93650a32fcbb8c4f9006c3514360))
* check Chronicle's own guarantees with assertions ([957059a](https://github.com/Larasell-dev/chronicle/commit/957059a3bb3b0c7363f5e9a08d9ee9b6bd3579d6))
* flatten objects through their JSON form, like arrays ([6f4d6a5](https://github.com/Larasell-dev/chronicle/commit/6f4d6a5c4703ecde5c0bd709cb1d81703ff9d825))
* keep the request fields Chronicle measured over Context ([e9180d1](https://github.com/Larasell-dev/chronicle/commit/e9180d1527701daafc9ad9f0c0486ddaf5d9e845))
* list redacted keys in chronicle.redacted ([3b6f383](https://github.com/Larasell-dev/chronicle/commit/3b6f383682447cbbc3190dc810674b03b333783d))
* log a query exception's SQL without its bindings ([01a9d7e](https://github.com/Larasell-dev/chronicle/commit/01a9d7eda2844d1dffac33f81acdf38e378116d3))
* log PHP's own exception code as error.exception_code ([a81161d](https://github.com/Larasell-dev/chronicle/commit/a81161d8363636b1b555c6e35e6567889a8dd731))
* log queued jobs when CHRONICLE_LOG_JOBS is on ([93a48e6](https://github.com/Larasell-dev/chronicle/commit/93a48e678b48b193ef13c543cbf6c1cd844a6dbd))
* log the query and path values as redacted fields ([da90cca](https://github.com/Larasell-dev/chronicle/commit/da90ccaf6d271e4abfdbbbac9b59828546f75ea9))
* make logging the client's ip optional ([c86d790](https://github.com/Larasell-dev/chronicle/commit/c86d790021c5fa8f96425c1376fbdda5200c7db8))
* redact values of secret keys in every event ([09bded9](https://github.com/Larasell-dev/chronicle/commit/09bded9b891fa722af29b861693ff44d5afcdd5a))
* replace values JSON can't encode instead of losing the event ([8ab9e3a](https://github.com/Larasell-dev/chronicle/commit/8ab9e3a6c7efb6cafea1fdaba95e8dda7c98ef1b))
* send PostHog events in batches, without OpenTelemetry ([3675e9f](https://github.com/Larasell-dev/chronicle/commit/3675e9f2cb04586d7edac269ce0a65cb18d3c4ad))
* take recordError arguments in record's order, with own Level ([944e379](https://github.com/Larasell-dev/chronicle/commit/944e379d0456c3e8c774cd9fd5536fbaded79690))


### Bug Fixes

* bound every value by its JSON-escaped size ([7ab24ab](https://github.com/Larasell-dev/chronicle/commit/7ab24aba4f3a8fb9be9d99582ab1f3eb01869d6b))
* log a request once when LogRequests runs twice ([25dcc8c](https://github.com/Larasell-dev/chronicle/commit/25dcc8c6784ddce4d3d56371240f776573268f2d))
* log no request id when the request has none ([2b160d7](https://github.com/Larasell-dev/chronicle/commit/2b160d76cafd39ef685499e37405889f5aec80d5))
* measure request durations with the monotonic clock ([fb1551b](https://github.com/Larasell-dev/chronicle/commit/fb1551b22a3a3954a098c1d18a4223f2f55907a1))
* report an exception context that is not an array ([e007949](https://github.com/Larasell-dev/chronicle/commit/e007949196ab2ba1a5941e0d65e674e97abae32d))
* warn when the PostHog channel has no API key ([8567155](https://github.com/Larasell-dev/chronicle/commit/856715537c043c7608ce871eb899c00b80dd899e))


### Performance Improvements

* register Chronicle's channels once at boot ([2acb392](https://github.com/Larasell-dev/chronicle/commit/2acb392653b94fda3c6c89d2a8baa1919d35a27e))
* resolve the sampler once, when the RequestLogger is built ([7a96839](https://github.com/Larasell-dev/chronicle/commit/7a9683990f044fa8b0fa40d29eb445b315234689))


### Code Refactoring

* make the error fields of structured exceptions private ([ea0712e](https://github.com/Larasell-dev/chronicle/commit/ea0712e730d181ae1293f0aef14ec4e43159b324))
* move Chronicle into the WideEvents namespace ([0c46634](https://github.com/Larasell-dev/chronicle/commit/0c4663420a129cf2b88dfb3ffe655ec68665da55))
* name the structured error code argument errorCode ([1ca5427](https://github.com/Larasell-dev/chronicle/commit/1ca54278c0f8b9db38209eafac96d22b75c1c797))
* rename the sampling config to sampler ([8816fea](https://github.com/Larasell-dev/chronicle/commit/8816fea7d1d942b885623813dfb4ae8f63ccd130))

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
