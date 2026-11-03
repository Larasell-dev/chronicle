# `ChronicleException` is removed

`ChronicleException::make()` built a structured exception without a class of its own. Chronicle
now has a structured version of each SPL exception for that, such as
`StructuredRuntimeException`, so a thrown exception keeps a meaningful parent type. Chronicle's
own errors are named classes: `InvalidSampler` and `MissingPostHogApiKey`.

## Who is affected

Apps that throw `Larasell\Chronicle\ChronicleException` or catch it. Apps that never mention it
are not affected.

## How to upgrade

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
