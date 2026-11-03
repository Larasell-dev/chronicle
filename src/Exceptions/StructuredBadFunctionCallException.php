<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Exceptions;

use BadFunctionCallException;
use Throwable;

/**
 * The structured BadFunctionCallException to throw inline, for programming errors that need no class of their own:
 * throw new StructuredBadFunctionCallException(errorCode: 'exports.unknown-formatter', message: 'No formatter named csv', ...).
 * For a named exception, implement StructuredException and use HasStructuredException instead.
 */
final class StructuredBadFunctionCallException extends BadFunctionCallException implements StructuredException
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
