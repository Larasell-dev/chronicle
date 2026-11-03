<?php

namespace Larasell\Chronicle;

use Larasell\Chronicle\Errors\HasStructuredError;
use Larasell\Chronicle\Errors\StructuredError;
use RuntimeException;
use Throwable;

class ChronicleException extends RuntimeException implements StructuredError
{
    use HasStructuredError;

    public static function make(
        string $code,
        string $message,
        ?string $why = null,
        ?string $fix = null,
        ?string $link = null,
        ?Throwable $previous = null,
    ): static {
        return static::structured($code, $message, $why, $fix, $link, $previous);
    }
}
