<?php

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
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
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
     * The exception's own context(), the same method Laravel's exception handler reads, so the
     * fields an app already attaches for its logs also reach the event. Top-level strings are cut
     * at 1000 bytes, so one large value, such as a response body, cannot bloat the event.
     */
    private static function exceptionContext(Throwable $e): array
    {
        if (method_exists($e, 'context')) {
            $context = $e->context();

            if (is_array($context)) {
                return array_map(
                    fn ($value) => is_string($value) ? substr($value, 0, 1000) : $value,
                    $context,
                );
            }
        }

        return [];
    }
}
