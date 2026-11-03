<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Exceptions;

use OutOfBoundsException;
use Throwable;

/**
 * The structured OutOfBoundsException to throw inline, for runtime failures that need no class of their own:
 * throw new StructuredOutOfBoundsException(code: 'inventory.unknown-sku', message: 'No stock entry for SKU-42', ...).
 * For a named exception, implement StructuredException and use HasStructuredException instead.
 */
final class StructuredOutOfBoundsException extends OutOfBoundsException implements StructuredException
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
