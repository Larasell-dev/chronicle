<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Exceptions;

use DomainException;
use Throwable;

/**
 * The structured DomainException to throw inline, for programming errors that need no class of their own:
 * throw new StructuredDomainException(errorCode: 'billing.unknown-currency', message: 'XYZ is not a currency', ...).
 * For a named exception, implement StructuredException and use HasStructuredException instead.
 */
final class StructuredDomainException extends DomainException implements StructuredException
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
