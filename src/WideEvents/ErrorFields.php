<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents;

use Larasell\Chronicle\Exceptions\StructuredException;
use Larasell\Chronicle\Exceptions\StructuredUnexpectedValueException;
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
        // PHP's own code, e.g. a PDOException's SQLSTATE or an HTTP status. 0 and '' are what
        // exceptions get when nobody set one, so they log as null rather than as a code.
        $exceptionCode = $exception->getCode();

        return array_merge(self::exceptionContext($exception), $fields, [
            'error' => [
                'code' => $structured ? $exception->errorCode() : null,
                'exception_code' => in_array($exceptionCode, [0, ''], true) ? null : $exceptionCode,
                'message' => $exception->getMessage(),
                'why' => $structured ? $exception->errorWhy() : null,
                'fix' => $structured ? $exception->errorFix() : null,
                'link' => $structured ? $exception->errorLink() : null,
            ],
        ]);
    }

    /**
     * The fields of the exception's context() method, the method Laravel's own reporting reads.
     * One that doesn't return an array is a bug in the app. In debug mode that throws, so the
     * developer sees it. In production the error is still recorded, since failing here would lose
     * the very error being reported, and chronicle.context_error says why its context is missing.
     *
     * @return array<mixed>
     */
    private static function exceptionContext(Throwable $exception): array
    {
        if (! method_exists($exception, 'context')) {
            return [];
        }

        $context = $exception->context();

        if (! is_array($context)) {
            $method = get_debug_type($exception).'::context()';
            $problem = "{$method} returned ".get_debug_type($context).', not an array';

            if (app()->hasDebugModeEnabled()) {
                throw new StructuredUnexpectedValueException(
                    errorCode: 'chronicle.error.invalid-context',
                    message: $problem,
                    why: 'Chronicle merges the context of an exception into its error event as fields, which needs an array',
                    fix: "Return an array from {$method}",
                );
            }

            return ['chronicle.context_error' => $problem];
        }

        // Chronicle::event() bounds every value of an event, these included.
        return $context;
    }
}
