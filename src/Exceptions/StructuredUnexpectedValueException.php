<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Exceptions;

use Throwable;
use UnexpectedValueException;

/**
 * The structured UnexpectedValueException to throw inline, for runtime failures that need no class of their own:
 * throw new StructuredUnexpectedValueException(errorCode: 'payments.unknown-status', message: 'The provider returned status frozen', ...).
 * For a named exception, implement StructuredException and use HasStructuredException instead.
 */
final class StructuredUnexpectedValueException extends UnexpectedValueException implements StructuredException
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
