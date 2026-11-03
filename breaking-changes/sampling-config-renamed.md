# `chronicle.sampling` is now `chronicle.sampler`

The config key holds a sampler class, so it is now named after one. The `Sampler` contract's
third parameter is renamed from `$payload` to `$event` too, because it receives the whole
flattened request event, not only the request's own fields.

## Who is affected

- Apps that published `config/chronicle.php` and set `sampling`. Chronicle stops at boot with
  `chronicle.sampling is now chronicle.sampler` until it is renamed, rather than quietly keeping
  every request event.
- Apps that call `Sampler::shouldLog()` with the named argument `payload:`. Implementations of
  `Sampler` keep working with any parameter name.

Apps that never set a sampler are not affected.

## How to upgrade

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
