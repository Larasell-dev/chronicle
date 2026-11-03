<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents;

/**
 * Decides what an app logs of each value, after Chronicle redacted the keys in chronicle.redact,
 * e.g. to mask emails or tokens by their pattern. Set the class as chronicle.redactor.
 */
interface Redactor
{
    /**
     * Called for every field and every element of an array or object, before Chronicle bounds it.
     * Return the value to log, which is $value itself to keep it.
     *
     * @param  string  $field  The dotted path of the value, e.g. user.email or items.0.token.
     */
    public function redact(string $field, mixed $value): mixed;
}
