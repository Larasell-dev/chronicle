<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Exceptions;

use OverflowException;
use Throwable;

/**
 * The structured OverflowException to throw inline, for runtime failures that need no class of their own:
 * throw new StructuredOverflowException(code: 'uploads.queue-full', message: 'The upload queue is full', ...).
 * For a named exception, implement StructuredException and use HasStructuredException instead.
 */
final class StructuredOverflowException extends OverflowException implements StructuredException
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
