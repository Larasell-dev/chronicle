<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Exceptions;

use OverflowException;
use Throwable;

/**
 * The structured OverflowException to throw inline, for runtime failures that need no class of their own:
 * throw new StructuredOverflowException(errorCode: 'uploads.queue-full', message: 'The upload queue is full', ...).
 * For a named exception, implement StructuredException and use HasStructuredException instead.
 */
final class StructuredOverflowException extends OverflowException implements StructuredException
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
