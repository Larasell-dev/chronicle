<?php

namespace Larasell\Chronicle\Exceptions;

use LogicException;
use Throwable;

/**
 * A structured exception to throw inline, for programming errors that need no class of their own:
 * throw new StructuredLogicException(code: 'checkout.missing-cart', message: 'No cart', ...).
 * For a named exception, implement StructuredException and use HasStructuredException instead.
 */
final class StructuredLogicException extends LogicException implements StructuredException
{
    use HasStructuredException;

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
