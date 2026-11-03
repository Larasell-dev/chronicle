<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Exceptions;

use OutOfRangeException;
use Throwable;

/**
 * The structured OutOfRangeException to throw inline, for programming errors that need no class of their own:
 * throw new StructuredOutOfRangeException(code: 'cart.no-such-line', message: 'The cart has no line 5', ...).
 * For a named exception, implement StructuredException and use HasStructuredException instead.
 */
final class StructuredOutOfRangeException extends OutOfRangeException implements StructuredException
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
