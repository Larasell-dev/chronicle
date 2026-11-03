# Classes moved to new namespaces

Chronicle's classes are now grouped by its two concepts: wide events in `WideEvents\` and
structured exceptions in `Exceptions\`. The structured error contract is now called a structured
exception, since it is implemented by exceptions.

## Who is affected

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

## How to upgrade

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
