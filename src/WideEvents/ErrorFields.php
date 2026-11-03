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
     * @param  array<mixed>  $fields
     * @return array<mixed>
     */
    public static function for(Throwable $exception, array $fields = []): array
    {
        $structured = $exception instanceof StructuredException;

        return array_merge(self::exceptionContext($exception), $fields, [
            'error' => [
                'code' => $structured ? $exception->errorCode() : null,
                'message' => $exception->getMessage(),
                'why' => $structured ? $exception->errorWhy() : null,
                'fix' => $structured ? $exception->errorFix() : null,
                'link' => $structured ? $exception->errorLink() : null,
            ],
        ]);
    }

    /**
     * @return array<mixed>
     */
    private static function exceptionContext(Throwable $exception): array
    {
        if (! method_exists($exception, 'context')) {
            return [];
        }

        $context = $exception->context();

        if (! is_array($context)) {
            return [];
        }

        // Chronicle::event() bounds every value of an event, these included.
        return $context;
    }
}
