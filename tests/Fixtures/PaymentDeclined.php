<?php

namespace Larasell\Chronicle\Tests\Fixtures;

use Exception;
use Larasell\Chronicle\Errors\HasStructuredError;
use Larasell\Chronicle\Errors\StructuredError;
use Throwable;

class PaymentDeclined extends Exception implements StructuredError
{
    use HasStructuredError;

    public static function byIssuer(?Throwable $previous = null): self
    {
        return self::structured(
            code: 'billing.declined',
            message: 'Payment failed',
            why: 'Issuer declined the charge',
            fix: 'Try a different card',
            link: 'https://docs.example.com/errors#billing-declined',
            previous: $previous,
        );
    }
}
