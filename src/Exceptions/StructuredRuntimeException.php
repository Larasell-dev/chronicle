<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The structured RuntimeException to throw inline, for runtime failures that need no class of their own:
 * throw new StructuredRuntimeException(code: 'billing.declined', message: 'Payment failed', ...).
 * For a named exception, implement StructuredException and use HasStructuredException instead.
 */
final class StructuredRuntimeException extends RuntimeException implements StructuredException
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
