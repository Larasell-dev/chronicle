<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Exceptions;

use LengthException;
use Throwable;

/**
 * The structured LengthException to throw inline, for programming errors that need no class of their own:
 * throw new StructuredLengthException(code: 'coupons.code-too-long', message: 'Coupon codes have at most 20 characters', ...).
 * For a named exception, implement StructuredException and use HasStructuredException instead.
 */
final class StructuredLengthException extends LengthException implements StructuredException
{
    use HasStructuredFields;

    public function __construct(
        string $code,
        string $message,
        ?string $why = null,
        ?string $fix = null,
        ?string $link = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);

        $this->withError($code, $why, $fix, $link);
    }
}
