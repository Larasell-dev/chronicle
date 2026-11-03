<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Exceptions;

use RangeException;
use Throwable;

/**
 * The structured RangeException to throw inline, for runtime failures that need no class of their own:
 * throw new StructuredRangeException(errorCode: 'shipping.no-rate', message: 'No rate covers a parcel of 80 kg', ...).
 * For a named exception, implement StructuredException and use HasStructuredException instead.
 */
final class StructuredRangeException extends RangeException implements StructuredException
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
