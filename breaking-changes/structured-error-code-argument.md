# Structured exceptions take `errorCode:` instead of `code:`

PHP exceptions already have a code, the one `getCode()` returns. Chronicle's `code:` argument set
something else, its string error code, so `new StructuredRuntimeException(code: 'billing.declined', ...)`
still returned `0` from `getCode()`. The argument is now named `errorCode:`, like the
`errorCode()` getter it fills.

Events also gain an `error.exception_code` field with PHP's own code, e.g. a `PDOException`'s
SQLSTATE or an HTTP status, for every exception. It is `null` when the code is `0` or `''`.
`error.code` is unchanged: Chronicle's string code, or `null` for exceptions that aren't structured.

## Who is affected

Calls that pass `code:` to any `Structured*Exception` constructor or to `structured()` in your own
exceptions. They now fail with `Unknown named parameter $code`.

Code that only reads `errorCode()`, or the `error.code` field, is not affected.

## How to upgrade

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
