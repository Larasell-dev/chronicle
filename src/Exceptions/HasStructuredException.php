<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Exceptions;

use Throwable;

/**
 * Makes an exception a StructuredException. structured() builds it with Exception's constructor,
 * so a class using it keeps that constructor; make the class final so subclasses can't change it.
 */
trait HasStructuredException
{
    use HasStructuredFields;

    protected static function structured(
        string $code,
        string $message,
        ?string $why = null,
        ?string $fix = null,
        ?string $link = null,
        ?Throwable $previous = null,
    ): static {
        $exception = new static($message, 0, $previous);

        return $exception->withError($code, $why, $fix, $link);
    }
}
