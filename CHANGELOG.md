# Changelog

## [0.4.1](https://github.com/Larasell-dev/chronicle/compare/v0.4.0...v0.4.1) (2026-11-03)


### Features

* add service, release, environment and host to every event ([5b7a072](https://github.com/Larasell-dev/chronicle/commit/5b7a0729420c973f78d1ed8ae473dfd26446a6f4))


### Bug Fixes

* ignore malformed global fields outside development ([48316dc](https://github.com/Larasell-dev/chronicle/commit/48316dc504cb82bdcbaa476e84f1ac5f73212a25))
* name NAN in the chronicle.fields error instead of interpolating it ([3093100](https://github.com/Larasell-dev/chronicle/commit/3093100a6e7fdf0950a465c90b2a3c43e36e0f85))

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

### Upgrade guide

#### Chronicle's channels are registered once, when the app boots

Chronicle used to copy every channel in `chronicle.channels` into `logging.channels` on every
event, so a change to `chronicle.channels` at runtime took effect on the next event. That cost
work on every event, and Laravel caches each logger on first use anyway, so changes to a channel
already used were ignored. Chronicle now registers the channels once, before any provider boots.
`chronicle.default`, the channel to write to, is still read on every event.

##### Who is affected

Code that changes `chronicle.channels` after the app booted: in a provider's `boot()`, a
middleware, a job or a test.

Apps that only set `chronicle.channels` in `config/chronicle.php`, and switch channels with
`CHRONICLE_CHANNEL` or `chronicle.default`, are not affected.

##### How to upgrade

Define channels in `config/chronicle.php`, or set them in a provider's `register()`, which runs
before boot:

```php
// Before: in AppServiceProvider::boot()
config()->set('chronicle.channels.audit', ['driver' => 'single', 'path' => storage_path('logs/audit.log')]);

// After: in AppServiceProvider::register()
config()->set('chronicle.channels.audit', ['driver' => 'single', 'path' => storage_path('logs/audit.log')]);
```

In tests with Orchestra Testbench, set them in `defineEnvironment()`. Then pick one per test with
`chronicle.default`:

```php
protected function defineEnvironment($app): void
{
    config()->set('chronicle.channels.test', ['driver' => 'custom', 'via' => fn () => new Logger('test', [new TestHandler])]);
}

// In a test
config()->set('chronicle.default', 'test');
```

#### `Chronicle` is now `Larasell\Chronicle\WideEvents\Chronicle`

The package has two features: structured exceptions in `Larasell\Chronicle\Exceptions`, and wide
events in `Larasell\Chronicle\WideEvents`. `Chronicle` records wide events, so it now lives with
the rest of them. The new `Level` enum lives there too, as `Larasell\Chronicle\WideEvents\Enums\Level`.

##### Who is affected

Every app that imports or type-hints `Larasell\Chronicle\Chronicle`, e.g. to call `record()` or
`recordError()`. There is no alias for the old name. Apps that only use the structured exceptions
or the `LogRequests` middleware are not affected.

##### How to upgrade

Change the import:

```php
// Before
use Larasell\Chronicle\Chronicle;

// After
use Larasell\Chronicle\WideEvents\Chronicle;
```

To update every file at once:

```bash
grep -rl 'Larasell\\Chronicle\\Chronicle\b' app routes bootstrap config tests \
  | xargs sed -i 's/Larasell\\Chronicle\\Chronicle\b/Larasell\\Chronicle\\WideEvents\\Chronicle/g'
```

#### Every event is bounded: strings, keys, nesting and field count have limits

Chronicle used to log a string field or a nested array at whatever size the app passed. One large
field in `Context` could make every log line of a request megabytes long. Lists were the only
values that had a limit, and they were cut at byte 1,000 with `substr()`, which could leave
invalid JSON or split a UTF-8 character. Now every part of an event has a limit. Each byte limit
counts the bytes a value takes in the log line, escapes included, so a string of quotes or control
characters keeps fewer characters than one of letters, and no event's line is longer than
`Chronicle::EVENT_BYTES_MAX` bytes, about 311 KB:

| What | Limit | What happens past it |
|---|---|---|
| A string value | 1,000 bytes (`Chronicle::VALUE_BYTES_MAX`) | Cut to the longest prefix that fits, on a UTF-8 character boundary |
| A list, stored as JSON | 1,000 bytes | Whole elements are left out, so it stays valid JSON, and `<key>_count` holds the full count |
| A key | 200 bytes | Cut on a character boundary |
| Nesting | 32 levels | Deeper arrays are stored as one JSON value |
| Fields per event | 256 | Fields only in `Context` are dropped first, latest first, and `chronicle.fields_dropped` holds how many |

##### Who is affected

- Apps that log strings longer than 1,000 bytes, such as request bodies, SQL or stack traces,
  and read them back in full.
- Apps that parse list fields. A cut list used to be truncated JSON; now it's valid JSON with
  fewer elements, plus a `<key>_count` field.
- Apps with more than 256 fields in one event, Context included.

Apps whose events stay within these limits see no difference, except that log lines now contain
non-ASCII characters as they are, e.g. `ü` instead of `\u00fc`, and `/` instead of `\/` inside
list values. JSON readers decode both the same way.

##### How to upgrade

Nothing to change in code. To keep more of a long value, log the parts you need as separate
fields, or log a reference to the full value:

```php
use Larasell\Chronicle\WideEvents\Chronicle;

// Before: the whole payload, any size
app(Chronicle::class)->record('webhook', ['payload' => $request->getContent()]);

// After: the fields you query on, and a pointer to the full body
app(Chronicle::class)->record('webhook', [
    'webhook.provider' => 'stripe',
    'webhook.event' => $request->input('type'),
    'webhook.body_path' => $storedPath,
]);
```

When you read a list field, check `<key>_count` to see if elements were left out:

```json
{"type":"tags","user.tags":"[\"tag-0\",\"tag-1\",…]","user.tags_count":200}
```

#### Objects in an event are flattened into dotted fields, like arrays

An object used to be stored as one value, whole: a `Collection`, a DTO or a `JsonSerializable`
was written at whatever size its JSON had, past every limit of the event. Now Chronicle first
turns an object into plain data, and that data follows the same rules as arrays:

| Object | Turned into |
|---|---|
| `JsonSerializable` | what `jsonSerialize()` returns |
| `Arrayable`, e.g. a `Collection` or a model | what `toArray()` returns |
| `stdClass` | its properties |
| Any other object | its public properties, through `json_encode` |

So an associative result becomes dotted fields, a list becomes one bounded JSON value with a
`<key>_count` when elements were left out, and a string is cut to 1,000 bytes.

##### Who is affected

Apps that pass objects as fields or put them in `Context`, and parse those fields:

```php
app(Chronicle::class)->record('checkout', ['customer' => (object) ['id' => 7, 'tier' => 'gold']]);
```

```json
// Before
{"type":"checkout","customer":{"id":7,"tier":"gold"}}

// After
{"type":"checkout","customer.id":7,"customer.tier":"gold"}
```

Apps that only log scalars and arrays are not affected.

##### How to upgrade

Query the dotted fields instead of the nested object, e.g. `customer.tier` rather than
`customer->tier`. A `jsonSerialize()` or `toArray()` that throws no longer costs the event: the
field becomes `null` and `chronicle.unencodable` names the method that threw.

#### The `posthog` channel sends batches of OTLP JSON, without the OpenTelemetry packages

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

##### Who is affected

- Apps that bound `OpenTelemetry\SDK\Logs\LoggerProviderInterface` in the container so Chronicle
  would send through their own OpenTelemetry pipeline. Chronicle no longer reads that binding.
- Apps that use the OpenTelemetry packages themselves but never required them, because Chronicle
  installed them. Require them in your own `composer.json`.
- Apps whose `composer.json` allows the `php-http/discovery` and `tbachert/spi` plugins only for
  Chronicle. You can remove those entries.

Logs in PostHog now carry your `APP_NAME` as `service.name`, instead of `unknown_service:php`.
Saved PostHog filters on the old service name need updating.

Apps that only set `POSTHOG_API_KEY` and `POSTHOG_HOST` need no changes.

##### How to upgrade

If your app uses OpenTelemetry itself, require it directly:

```bash
composer require open-telemetry/api open-telemetry/sdk open-telemetry/exporter-otlp
```

If you sent Chronicle's events through your own OpenTelemetry pipeline, there is no replacement
for the container binding. Either let the `posthog` channel send to PostHog directly, or have your
collector read the JSON lines the `file` channel writes to `storage/logs/chronicle.log`.

#### A failed query's `error.message` no longer contains its values

Laravel's `QueryException` puts the query's bindings into its message, e.g.
`insert into users (email, password) values (jane@example.com, $2y$12$…)`, so Chronicle logged
emails, password hashes and tokens with every failed query. Chronicle now logs the SQL with its
`?` placeholders instead. The database's own message, which comes first, is unchanged and can
still quote a value, e.g. the entry that broke a unique index.

```text
// Before
SQLSTATE[HY000]: General error (Connection: mysql, SQL: insert into users (email) values (jane@example.com))

// After
SQLSTATE[HY000]: General error (Connection: mysql, SQL: insert into users (email) values (?))
```

##### Who is affected

Apps that read the bindings of failed queries from `error.message` in their logs or in PostHog.
Error events of other exceptions are not affected.

##### How to upgrade

Log the values you need yourself, under keys that aren't secret:

```php
// Before: the email was in error.message
$user->save();

// After
Context::add('signup.email_domain', Str::after($user->email, '@'));
$user->save();
```

#### `recordError()` takes its arguments in the same order as `record()`, with Chronicle's own `Level`

`recordError()` took the level second and the fields third, the reverse of `record()`, and named
the fields `$context` instead of `$fields`. Both methods now take fields second and the level last.
Levels are now Chronicle's own `Larasell\Chronicle\WideEvents\Enums\Level` enum, so apps no longer need Monolog to
pick one. Level names as strings still work, in any case; an unknown name now throws a structured
`chronicle.level.unknown` exception instead of Monolog's `ValueError`.

##### Who is affected

- Calls to `recordError()` that pass a level, or fields by the name `context:`.
- Calls to `record()` or `recordError()` that pass a `Monolog\Level` case. Strings such as
  `'warning'` keep working.

Apps that call `recordError($exception)` and `record($type, $fields)` without a level are not
affected.

##### How to upgrade

```php
// Before
use Monolog\Level;

app(Chronicle::class)->recordError($exception, Level::Warning, ['order.id' => 7]);
app(Chronicle::class)->recordError($exception, context: ['order.id' => 7]);
app(Chronicle::class)->record('inventory.low', ['sku' => $sku], Level::Warning);

// After
use Larasell\Chronicle\WideEvents\Enums\Level;

app(Chronicle::class)->recordError($exception, ['order.id' => 7], Level::Warning);
app(Chronicle::class)->recordError($exception, fields: ['order.id' => 7]);
app(Chronicle::class)->record('inventory.low', ['sku' => $sku], Level::Warning);

// A level without fields: name it
app(Chronicle::class)->recordError($exception, level: 'warning');
```

#### Context can no longer replace the request fields Chronicle measured

A request's event used to take any `Context` field with the same key as a request field, so a
package that put `status` or `route` into Context changed the event of every request, also the
requests of other packages. The fields Chronicle measured itself, `request.id`, `method`, `url`,
`route`, `status`, `duration_ms`, `ip` and `user.id`, now always win, and so do an event's
`timestamp` and `type`. Context can still fill in a request field that is `null`.

To add fields to the event of the current request without sharing them with every log line and
queued job, use the new `Chronicle::add()`.

##### Who is affected

Apps that use `Context` to replace a request field that Chronicle measured, e.g. a `user.id` set
in Context while a user is signed in through the default guard, or any Context key named
`timestamp` or `type`.

Apps that only add their own keys to Context, or fill in `user.id` for guests, e.g. for customers
signed in through another guard, are not affected.

##### How to upgrade

Keep a measured value under a key of its own:

```php
use Illuminate\Support\Facades\Context;

// Before: replaced the id of the signed-in user
Context::add('user.id', $customer->id);

// After
Context::add('customer.id', $customer->id);
```

For fields that only belong on the request's event:

```php
use Larasell\Chronicle\WideEvents\Chronicle;

app(Chronicle::class)->add('payment', ['provider' => 'stripe', 'attempts' => 2]);
```

#### `chronicle.sampling` is now `chronicle.sampler`

The config key holds a sampler class, so it is now named after one. The `Sampler` contract's
third parameter is renamed from `$payload` to `$event` too, because it receives the whole
flattened request event, not only the request's own fields.

##### Who is affected

- Apps that published `config/chronicle.php` and set `sampling`. Chronicle stops at boot with
  `chronicle.sampling is now chronicle.sampler` until it is renamed, rather than quietly keeping
  every request event.
- Apps that call `Sampler::shouldLog()` with the named argument `payload:`. Implementations of
  `Sampler` keep working with any parameter name.

Apps that never set a sampler are not affected.

##### How to upgrade

Rename the key in `config/chronicle.php`:

```php
// Before
'sampling' => App\Logging\KeepFailuresAndSlowRequests::class,

// After
'sampler' => App\Logging\KeepFailuresAndSlowRequests::class,
```

Optionally rename the parameter in your sampler to match the contract:

```php
// Before
public function shouldLog(Request $request, Response $response, array $payload): bool

// After
public function shouldLog(Request $request, Response $response, array $event): bool
```

#### Chronicle redacts the values of secret keys

Chronicle used to log every value as it was, so a `password`, `api_key` or `token` that reached an
event through fields, `Context`, an exception's `context()` or the query was written to the log
file and sent to PostHog. Chronicle now logs the value of every key that contains a word from the
new `chronicle.redact` list as `"[redacted]"`, wherever the key is in an event, lists and objects
included. The default list covers passwords, secrets, tokens, API keys, cookies, signatures and
card numbers. Keys match by whole words, in any case: `token` matches `access_token`,
`X-Csrf-Token` and `apiToken`, but not `tokens`. An event with hidden values lists their paths in
`chronicle.redacted`, e.g. `["user.password","items.0.token"]`.

##### Who is affected

Apps that log a value under a key such as `token`, `secret`, `signature` or `password` and read it
from the log, e.g. a `token_type` or `password_changed_at` field. Apps with a published
`config/chronicle.php` get the default list too, since the config merges it in.

##### How to upgrade

Rename fields you need to keep, or set your own list in `config/chronicle.php`:

```php
// Before: logged as is
Context::add('password_changed_at', $user->password_changed_at);

// After: logged as "[redacted]", so give it a key without the word
Context::add('user.days_since_reset', $user->password_changed_at->diffInDays());
```

```php
// config/chronicle.php: your own list replaces the default one
'redact' => [
    'password', 'secret', 'token', 'api_key', 'apikey', 'authorization', 'cookie',
    'credential', 'credentials', 'private_key', 'signature', 'sig', 'card_number',
    'cvc', 'cvv', 'iban',
],
```

#### Structured exceptions take `errorCode:` instead of `code:`

PHP exceptions already have a code, the one `getCode()` returns. Chronicle's `code:` argument set
something else, its string error code, so `new StructuredRuntimeException(code: 'billing.declined', ...)`
still returned `0` from `getCode()`. The argument is now named `errorCode:`, like the
`errorCode()` getter it fills.

Events also gain an `error.exception_code` field with PHP's own code, e.g. a `PDOException`'s
SQLSTATE or an HTTP status, for every exception. It is `null` when the code is `0` or `''`.
`error.code` is unchanged: Chronicle's string code, or `null` for exceptions that aren't structured.

##### Who is affected

Calls that pass `code:` to any `Structured*Exception` constructor or to `structured()` in your own
exceptions. They now fail with `Unknown named parameter $code`.

Code that only reads `errorCode()`, or the `error.code` field, is not affected.

##### How to upgrade

Rename the argument:

```php
use Larasell\Chronicle\Exceptions\StructuredRuntimeException;

// Before
throw new StructuredRuntimeException(code: 'billing.declined', message: 'Payment failed');

// After
throw new StructuredRuntimeException(errorCode: 'billing.declined', message: 'Payment failed');
```

```php
// Before
return self::structured(code: 'billing.declined', message: 'Payment failed');

// After
return self::structured(errorCode: 'billing.declined', message: 'Payment failed');
```

To find the calls, including ones that put each argument on its own line:

```bash
grep -rnE "\bcode: ['\"]" app
```

#### The error fields of structured exceptions are private

`$errorCode`, `$errorWhy`, `$errorFix` and `$errorLink` were public, so any code that caught a
structured exception could rewrite or erase them before Chronicle logged it. They are now private:
an exception sets them once, while it is built, and everyone else reads them through
`errorCode()`, `errorWhy()`, `errorFix()` and `errorLink()`.

##### Who is affected

- Code that reads the properties directly, e.g. `$exception->errorCode`.
- Exceptions that use `HasStructuredException` or `HasStructuredFields` and set the properties
  from outside the class, e.g. right after `new`.

Exceptions built with `structured()`, `new Structured*Exception(...)`, or `withError()` in their
constructor are not affected, and neither are calls to the getters.

##### How to upgrade

Read through the getters:

```php
// Before
$code = $exception->errorCode;

// After
$code = $exception->errorCode();
```

Set the fields while the exception is built, with `withError()` or `structured()`:

```php
use Larasell\Chronicle\Exceptions\HasStructuredException;
use Larasell\Chronicle\Exceptions\StructuredException;

// Before
final class PaymentDeclined extends RuntimeException implements StructuredException
{
    use HasStructuredException;
}

$exception = new PaymentDeclined('Payment failed');
$exception->errorCode = 'billing.declined';
$exception->errorFix = 'Try a different card';

// After
final class PaymentDeclined extends RuntimeException implements StructuredException
{
    use HasStructuredException;

    public static function forCard(): self
    {
        return self::structured(
            errorCode: 'billing.declined',
            message: 'Payment failed',
            fix: 'Try a different card',
        );
    }
}

$exception = PaymentDeclined::forCard();
```

#### Values JSON can't encode become `null`, instead of costing the whole event

A field `json_encode` couldn't encode, such as `NAN`, `INF`, a resource, or an object whose
`jsonSerialize()` throws, used to make the whole event fail to encode, and Chronicle wrote an
empty line. The event was lost without a trace. Now only that field becomes `null`, and
`chronicle.unencodable` says which fields and why, as a JSON object:

```json
{"type":"checkout","order.id":7,"conversion":null,"ratio":null,
 "chronicle.unencodable":"{\"conversion\":\"NAN\",\"ratio\":\"INF\"}"}
```

In a list, only the bad elements become `null`: `[1,null,2]`.

##### Who is affected

- Apps that log floats that can be `NAN` or `INF`, e.g. a ratio divided by zero, or resources
  such as file handles. Those events now show up, with `null` in place of the value.
- Apps that look for empty lines in `chronicle.log` to spot failed events. Search for
  `chronicle.unencodable` instead.
- The posthog channel: a message that isn't a JSON object now throws `chronicle.posthog.invalid-event`
  in debug mode. Outside debug mode it is sent with `chronicle.decode_error`.

Apps that only log strings, ints, bools and arrays of them are not affected.

##### How to upgrade

Nothing is required. To find the events that had a bad value:

```bash
grep '"chronicle.unencodable"' storage/logs/chronicle.log
```

To keep the information, log the value in a form JSON can hold:

```php
// Before: NAN when there were no visits
app(Chronicle::class)->record('report', ['conversion' => $orders / $visits]);

// After
app(Chronicle::class)->record('report', ['conversion' => $visits > 0 ? $orders / $visits : null]);
```

#### A request's `url` has no query and the route's template as its path

`url` used to be the full url, query included, so tokens and signed-url signatures in the query
were logged as they were. `url` is now the url without the query, and each query value is a field
of its own, `query.<name>`, so [redaction](secret-keys-redacted.md) can hide the values of secret
keys. A request event keeps at most 32 query values; past that, `query_count` holds how many the
query has. A url without a query gets no `query` fields.

Values in the path, such as a password reset token, were logged as they were too. When a route
matched, the path in `url` is now the route's template, and each route parameter is a field of its
own, `route_params.<name>`, with its value from the path, so the same redaction applies. A request
that matches no route, such as a 404, keeps its path.

```json
// Before: /orders?page=2&token=abc
{"url": "https://shop.test/orders?page=2&token=abc"}

// After
{"url": "https://shop.test/orders", "query.page": "2", "query.token": "[redacted]"}

// Before: /reset-password/9f2c1a
{"url": "https://shop.test/reset-password/9f2c1a"}

// After
{"url": "https://shop.test/reset-password/{token}", "route_params.token": "[redacted]"}
```

##### Who is affected

Dashboards, alerts, samplers and queries that read the query or a path value from `url`, e.g. in
PostHog or with `$event['url']` in a `Sampler`. Grouping by `url` now groups by route, which
merges the requests of every order, user or token.

Apps that only use `route`, or `url` of routes without parameters, are not affected.

##### How to upgrade

Read query values from their own fields:

```php
// Before
public function shouldLog(Request $request, Response $response, array $event): bool
{
    return str_contains($event['url'], 'debug=1');
}

// After
public function shouldLog(Request $request, Response $response, array $event): bool
{
    return ($event['query.debug'] ?? null) === '1';
}
```

Read path values from `route_params`:

```php
// Before: /orders/7
$orderId = (int) basename($event['url']);

// After
$orderId = (int) $event['route_params.order'];
```

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
