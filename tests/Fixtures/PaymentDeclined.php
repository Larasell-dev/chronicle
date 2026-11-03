<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Tests\Fixtures;

use Exception;
use Larasell\Chronicle\Exceptions\HasStructuredException;
use Larasell\Chronicle\Exceptions\StructuredException;
use Throwable;

final class PaymentDeclined extends Exception implements StructuredException
{
    use HasStructuredException;

    public static function byIssuer(?Throwable $previous = null): self
    {
        return self::structured(
            errorCode: 'billing.declined',
            message: 'Payment failed',
            why: 'Issuer declined the charge',
            fix: 'Try a different card',
            link: 'https://docs.example.com/errors#billing-declined',
            previous: $previous,
        );
    }
}
