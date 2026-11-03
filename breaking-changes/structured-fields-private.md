# The error fields of structured exceptions are private

`$errorCode`, `$errorWhy`, `$errorFix` and `$errorLink` were public, so any code that caught a
structured exception could rewrite or erase them before Chronicle logged it. They are now private:
an exception sets them once, while it is built, and everyone else reads them through
`errorCode()`, `errorWhy()`, `errorFix()` and `errorLink()`.

## Who is affected

- Code that reads the properties directly, e.g. `$exception->errorCode`.
- Exceptions that use `HasStructuredException` or `HasStructuredFields` and set the properties
  from outside the class, e.g. right after `new`.

Exceptions built with `structured()`, `new Structured*Exception(...)`, or `withError()` in their
constructor are not affected, and neither are calls to the getters.

## How to upgrade

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
