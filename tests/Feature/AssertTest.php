<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Exceptions;
use Larasell\Chronicle\Exceptions\StructuredLogicException;
use Larasell\Chronicle\Support\Assert;
use Larasell\Chronicle\WideEvents\Requests\RequestLogger;

/**
 * Makes the app look like production: no debug mode, and an environment that isn't local or testing.
 */
it('throws a structured logic exception in tests', function () {
    // Act & Assert
    Assert::that(false, 'chronicle.assert.example', 'Something Chronicle promised is false');
})->throws(StructuredLogicException::class, 'Something Chronicle promised is false');

it('throws in debug mode in production too', function () {
    // Arrange
    app()->instance('env', 'production');
    config()->set('app.debug', true);

    // Act & Assert
    Assert::that(false, 'chronicle.assert.example', 'Something Chronicle promised is false');
})->throws(StructuredLogicException::class);

it('reports a failed check once per error code in production and lets the caller carry on', function () {
    // Arrange
    Exceptions::fake();
    inProduction();

    // Act
    $results = [
        Assert::that(false, 'chronicle.assert.example', 'first'),
        Assert::that(false, 'chronicle.assert.example', 'second'),
        Assert::that(false, 'chronicle.assert.other', 'third'),
        Assert::that(true, 'chronicle.assert.example', 'holds'),
    ];

    // Assert
    expect($results)->toBe([false, false, false, true]);
    Exceptions::assertReportedCount(2);
    Exceptions::assertReported(fn (StructuredLogicException $exception): bool => $exception->errorCode() === 'chronicle.assert.example'
        && $exception->getMessage() === 'first');
});

it('writes to the error log when report fails in production', function () {
    // Arrange
    inProduction();
    $errorLog = $this->captureErrorLog();
    Exceptions::fake()->throwOnReport();

    // Act
    Assert::that(false, 'chronicle.assert.example', 'Something Chronicle promised is false');

    // Assert
    expect((string) file_get_contents($errorLog))->toContain(
        'Chronicle assertion chronicle.assert.example failed: Something Chronicle promised is false (report() threw',
    );
});

it('still logs the request, without a duration, when its start lies in the future in production', function () {
    // Arrange
    Exceptions::fake();
    $handler = chronicleTestHandler();
    inProduction();

    // Act
    app(RequestLogger::class)->log(Request::create('/cart'), response('ok'), hrtime(true) + 5_000_000_000);

    // Assert
    $entry = chronicleEntries($handler)[0];
    $failures = chronicleEntry(Arr::string($entry, 'chronicle.assertion_failed'));

    expect($entry['status'])->toBe(200)
        ->and($entry['duration_ms'])->toBeNull()
        ->and(array_keys($failures))->toBe(['chronicle.request.negative-duration'])
        ->and($failures['chronicle.request.negative-duration'])->toContain('ns in the future');
    Exceptions::assertReported(StructuredLogicException::class);
});
