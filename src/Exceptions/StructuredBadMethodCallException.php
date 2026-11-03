<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Exceptions;

use BadMethodCallException;
use Throwable;

/**
 * The structured BadMethodCallException to throw inline, for programming errors that need no class of their own:
 * throw new StructuredBadMethodCallException(errorCode: 'orders.already-shipped', message: 'A shipped order cannot be cancelled', ...).
 * For a named exception, implement StructuredException and use HasStructuredException instead.
 */
final class StructuredBadMethodCallException extends BadMethodCallException implements StructuredException
{
    use HasStructuredFields;

    public function __construct(
        string $errorCode,
        string $message,
        ?string $why = null,
        ?string $fix = null,
        ?string $link = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);

        $this->withError($errorCode, $why, $fix, $link);
    }
}
