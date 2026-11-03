# Request ids are assigned when the request starts

`LogRequests` now gives each request its id before the app handles it, instead of when it writes
the request event. The id is stored in `Context` as `request.id`, so every log line of the
request carries it, and is sent back in the `X-Request-Id` response header. The id is the
client's `X-Request-Id` when it is at most 200 visible ASCII characters, and otherwise a UUIDv7
instead of 16 random hex characters.

## Who is affected

Apps that use `LogRequests` on some routes only, or after other middleware, because log lines
written before `LogRequests` runs have no `request.id`. Apps that parse `request.id` and expect
16 hex characters. With `CHRONICLE_ENABLED=false`, requests get no id and no header.

## How to upgrade

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
