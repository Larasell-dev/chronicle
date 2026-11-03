<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Support;

use Larasell\Chronicle\Exceptions\StructuredLogicException;
use Throwable;

/**
 * Checks Chronicle's own guarantees, such as the bounds of an event. A failed check is a bug in
 * Chronicle, never in the app, so bad input from apps keeps its own structured exceptions.
 *
 * Where assertions go: a function that promises something, e.g. a bound, asserts it before it
 * returns, and the code that relies on it asserts it again where it uses the result. A pair is a
 * contract; one side alone is only a comment. Both sides use the same error code, so a broken
 * promise is reported once, not once per side.
 *
 * What a failed check does:
 * - In debug mode, and in the local and testing environments, it throws a StructuredLogicException,
 *   so tests and developers see it at once.
 * - Everywhere else it reports the exception through Laravel's report(), once per error code and
 *   process, and returns false so the caller can carry on: logging must never take the app down.
 *   When report() fails, or reports back into a failing check, the failure goes to PHP's
 *   error_log() instead.
 *
 * Not PHP's assert(): production sets zend.assertions to -1, which compiles those out.
 *
 * @internal
 */
final class Assert
{
    private const WHY = 'Chronicle broke one of its own guarantees, which is a bug in Chronicle';

    private const FIX = 'Report it at https://github.com/larasell-dev/chronicle/issues';

    /** @var array<string, true> The error codes this process already reported. */
    private array $reported = [];

    /** Whether report() is running, so a check that fails inside it can't report again. */
    private bool $reporting = false;

    /**
     * Returns the condition, so outside debug mode the caller can fall back when it is false.
     */
    public static function that(bool $condition, string $errorCode, string $message): bool
    {
        if ($condition) {
            return true;
        }

        app(self::class)->fail($errorCode, new StructuredLogicException(
            errorCode: $errorCode,
            message: $message,
            why: self::WHY,
            fix: self::FIX,
        ));

        return false;
    }

    private function fail(string $errorCode, StructuredLogicException $exception): void
    {
        if (app()->hasDebugModeEnabled()) {
            throw $exception;
        }

        if (app()->environment('local', 'testing')) {
            throw $exception;
        }

        if (isset($this->reported[$errorCode])) {
            return;
        }

        $this->reported[$errorCode] = true;

        if ($this->reporting) {
            $this->errorLog($exception, null);

            return;
        }

        $this->reporting = true;

        try {
            report($exception);
        } catch (Throwable $throwable) {
            $this->errorLog($exception, $throwable);
        } finally {
            $this->reporting = false;
        }
    }

    private function errorLog(StructuredLogicException $exception, ?Throwable $reportFailure): void
    {
        $line = "Chronicle assertion {$exception->errorCode()} failed: {$exception->getMessage()}";

        if ($reportFailure !== null) {
            $line .= ' (report() threw '.$reportFailure::class.': '.$reportFailure->getMessage().')';
        }

        error_log($line);
    }
}
