<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents;

use Larasell\Chronicle\Exceptions\StructuredException;
use Throwable;

/**
 * Builds the error fields of an event from an exception. Error events and failed request events
 * both use it, so an exception is logged the same way in either.
 *
 * @internal
 */
final class ErrorFields
{
    /**
     * @param  array<mixed>  $context
     * @return array<mixed>
     */
    public static function for(Throwable $e, array $context = []): array
    {
        $structured = $e instanceof StructuredException;

        return array_merge(self::exceptionContext($e), $context, [
            'error' => [
                'code' => $structured ? $e->errorCode() : null,
                'message' => $e->getMessage(),
                'why' => $structured ? $e->errorWhy() : null,
                'fix' => $structured ? $e->errorFix() : null,
                'link' => $structured ? $e->errorLink() : null,
            ],
        ]);
    }

    /**
     * @return array<mixed>
     */
    private static function exceptionContext(Throwable $e): array
    {
        if (! method_exists($e, 'context')) {
            return [];
        }

        $context = $e->context();

        if (! is_array($context)) {
            return [];
        }

        return array_map(
            fn ($value) => is_string($value) && strlen($value) > 1000 ? substr($value, 0, 1000) : $value,
            $context,
        );
    }
}
