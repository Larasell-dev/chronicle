<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Tests\Fixtures;

use Larasell\Chronicle\WideEvents\Redactor;

class EmailMaskingRedactor implements Redactor
{
    public function redact(string $field, mixed $value): mixed
    {
        if (is_string($value) && str_contains($value, '@')) {
            return '***@'.substr($value, strpos($value, '@') + 1);
        }

        return $value;
    }
}
