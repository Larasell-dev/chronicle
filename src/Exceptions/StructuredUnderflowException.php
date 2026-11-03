<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Exceptions;

use Throwable;
use UnderflowException;

/**
 * The structured UnderflowException to throw inline, for runtime failures that need no class of their own:
 * throw new StructuredUnderflowException(code: 'jobs.queue-empty', message: 'No job is left to take', ...).
 * For a named exception, implement StructuredException and use HasStructuredException instead.
 */
final class StructuredUnderflowException extends UnderflowException implements StructuredException
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
