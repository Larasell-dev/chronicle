<?php

declare(strict_types=1);

// How: every test sends through a real posthog channel and Laravel's faked HTTP client, then
// reads the OTLP JSON requests the fake recorded. TestCase prevents stray requests, so nothing
// here reaches PostHog.

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Logger;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Larasell\Chronicle\WideEvents\Chronicle;
use Larasell\Chronicle\WideEvents\Outputs\Exceptions\MissingPostHogApiKey;
use Larasell\Chronicle\WideEvents\Outputs\PostHogHandler;
use Monolog\Level;

// Laravel matches fake stubs in the order they were added, so a test that needs PostHog to fail
// fakes that itself instead of adding to a shared success stub.
beforeEach(function () {
    config()->set('app.name', 'Shop');
    config()->set('logging.channels.posthog_test', [
        'driver' => 'posthog',
        'api_key' => 'phc_test',
        'host' => 'https://eu.i.posthog.com/',
        'level' => 'debug',
    ]);
});

/**
 * The decoded body of every request sent to PostHog, in order.
 *
 * @return list<array<mixed>>
 */
function posthogPayloads(): array
{
    $payloads = [];

    foreach (Http::recorded() as $pair) {
        $request = is_array($pair) ? ($pair[0] ?? null) : null;

        if (! $request instanceof Request) {
            throw new UnexpectedValueException('Laravel recorded something other than a request.');
        }

        $payloads[] = chronicleEntry($request->body());
    }

    return $payloads;
}

/**
 * The log records of every request sent to PostHog, in order.
 *
 * @return list<list<array<mixed>>>
 */
function posthogBatches(): array
{
    return array_map(function (array $payload): array {
        $records = [];

        foreach (Arr::array($payload, 'resourceLogs.0.scopeLogs.0.logRecords') as $record) {
            $records[] = is_array($record) ? $record : throw new UnexpectedValueException('A log record must be an object.');
        }

        return $records;
    }, posthogPayloads());
}

/**
 * A log record's attributes as a key => OTLP value map.
 *
 * @param  array<mixed>  $record
 * @return array<string, mixed>
 */
function posthogAttributes(array $record): array
{
    $attributes = [];

    foreach (Arr::array($record, 'attributes') as $attribute) {
        if (! is_array($attribute)) {
            throw new UnexpectedValueException('An attribute must be an object.');
        }

        $attributes[Arr::string($attribute, 'key')] = Arr::array($attribute, 'value');
    }

    return $attributes;
}

function posthogTestHandler(): PostHogHandler
{
    $logger = Log::channel('posthog_test');
    $monolog = $logger instanceof Logger ? $logger->getLogger() : null;
    $handler = $monolog instanceof Monolog\Logger ? ($monolog->getHandlers()[0] ?? null) : null;

    if (! $handler instanceof PostHogHandler) {
        throw new UnexpectedValueException('The posthog_test channel must use the PostHogHandler.');
    }

    return $handler;
}

it('sends chronicle events as otlp json log records', function () {
    // Arrange
    Http::fake();

    // Act
    Log::channel('posthog_test')->info(json_encode([
        'timestamp' => '2026-10-07T20:00:00+00:00',
        'type' => 'request',
        'method' => 'GET',
        'status' => 200,
        'duration_ms' => 1.5,
        'cached' => false,
        'user.id' => 42,
        'session.id' => 'sess_abc',
        'route' => null,
    ], JSON_THROW_ON_ERROR));

    app()->terminate();

    // Assert
    // One request, so the three checks below are all about it.
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://eu.i.posthog.com/i/v1/logs');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer phc_test'));
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Content-Type', 'application/json'));

    $payload = posthogPayloads()[0];
    $record = posthogBatches()[0][0];

    expect(Arr::get($payload, 'resourceLogs.0.resource.attributes'))
        ->toBe([['key' => 'service.name', 'value' => ['stringValue' => 'Shop']]])
        ->and(Arr::get($payload, 'resourceLogs.0.scopeLogs.0.scope.name'))->toBe('chronicle')
        ->and($record['body'])->toBe(['stringValue' => 'request'])
        ->and($record['severityNumber'])->toBe(9)
        ->and($record['severityText'])->toBe('INFO')
        ->and($record['timeUnixNano'])->toBeString()->toMatch('/^\d{19}$/')
        ->and(posthogAttributes($record))->toBe([
            'method' => ['stringValue' => 'GET'],
            'status' => ['intValue' => '200'],
            'duration_ms' => ['doubleValue' => 1.5],
            'cached' => ['boolValue' => false],
            'user.id' => ['intValue' => '42'],
            'session.id' => ['stringValue' => 'sess_abc'],
            'posthogDistinctId' => ['stringValue' => '42'],
            'sessionId' => ['stringValue' => 'sess_abc'],
        ]);
});

it('maps monolog levels to otlp severities', function (Level $level, int $number, string $text) {
    // Arrange
    Http::fake();

    // Act
    Log::channel('posthog_test')->log($level->getName(), '{"type":"request"}');
    posthogTestHandler()->flush();

    // Assert
    expect(posthogBatches()[0][0]['severityNumber'])->toBe($number)
        ->and(posthogBatches()[0][0]['severityText'])->toBe($text);
})->with([
    [Level::Debug, 5, 'DEBUG'],
    [Level::Info, 9, 'INFO'],
    [Level::Notice, 9, 'INFO'],
    [Level::Warning, 13, 'WARN'],
    [Level::Error, 17, 'ERROR'],
    [Level::Critical, 21, 'FATAL'],
    [Level::Alert, 21, 'FATAL'],
    [Level::Emergency, 21, 'FATAL'],
]);

it('sends a request\'s events in one batch once the app terminates', function () {
    // Arrange
    Http::fake();

    foreach (['one', 'two', 'three'] as $type) {
        Log::channel('posthog_test')->info(json_encode(['type' => $type], JSON_THROW_ON_ERROR));
    }

    expect(Http::recorded())->toHaveCount(0);

    // Act
    app()->terminate();

    // Assert
    expect(array_map(fn (array $record): mixed => $record['body'], posthogBatches()[0]))
        ->toBe([['stringValue' => 'one'], ['stringValue' => 'two'], ['stringValue' => 'three']]);
});

it('sends a batch early once it holds 100 events', function () {
    // Arrange
    Http::fake();

    // Act
    foreach (range(1, 101) as $index) {
        Log::channel('posthog_test')->info('{"type":"tick"}');
    }

    // Assert
    // The 100th event fills the batch; the 101st waits for the next one.
    expect(posthogBatches())->toHaveCount(1)
        ->and(posthogBatches()[0])->toHaveCount(100);

    posthogTestHandler()->flush();

    expect(posthogBatches()[1])->toHaveCount(1);
});

it('sends a batch early rather than let it pass 1 MB', function () {
    // Arrange
    Http::fake();

    $event = json_encode(['type' => 'big', 'body' => str_repeat('x', 600_000)], JSON_THROW_ON_ERROR);

    // Act
    Log::channel('posthog_test')->info($event);
    Log::channel('posthog_test')->info($event);

    // Assert
    // Two 600 KB events can't share a batch, so the first one went before the second was added.
    expect(posthogBatches())->toHaveCount(1)
        ->and(posthogBatches()[0])->toHaveCount(1);
});

it('sends the events of a queued job after the job', function () {
    // Arrange
    Http::fake();

    Log::channel('posthog_test')->info('{"type":"job"}');

    // Act
    Event::dispatch(new JobProcessed('sync', new SyncJob(app(), '{}', 'sync', 'default')));

    // Assert
    expect(posthogBatches())->toHaveCount(1);
});

it('retries once and then drops the batch without throwing when posthog fails', function () {
    // Arrange
    Http::fake(['*' => Http::response([], 503)]);
    $errorLog = $this->captureErrorLog();

    // Act
    Log::channel('posthog_test')->info('{"type":"request"}');
    app()->terminate();
    posthogTestHandler()->flush();

    // Assert
    expect(Http::recorded())->toHaveCount(2)
        ->and((string) file_get_contents($errorLog))->toContain('Chronicle dropped 1 events for PostHog: PostHog answered HTTP 503');
});

it('drops the batch without throwing when posthog can not be reached', function () {
    // Arrange
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Connection timed out'));
    $errorLog = $this->captureErrorLog();

    // Act
    Log::channel('posthog_test')->info('{"type":"request"}');
    app()->terminate();

    // Assert
    expect((string) file_get_contents($errorLog))
        ->toContain('Chronicle dropped 1 events for PostHog: cURL error 28: Connection timed out');
});

it('throws in debug mode when the posthog channel is enabled without an api key', function () {
    // Arrange
    Http::fake();

    config()->set('app.debug', true);
    config()->set('chronicle.channels.posthog.api_key', null);
    config()->set('chronicle.default', 'posthog');

    // Act & Assert
    app(Chronicle::class)->record('request');
})->throws(MissingPostHogApiKey::class, 'POSTHOG_API_KEY is required');

it('does not throw outside debug mode when the api key is missing', function () {
    // Arrange
    Http::fake();

    config()->set('app.debug', false);
    config()->set('chronicle.channels.posthog.api_key', null);
    config()->set('chronicle.default', 'posthog');

    // Act
    app(Chronicle::class)->record('request');
    app()->terminate();

    // Assert
    Http::assertNothingSent();
});

it('writes only to the log file by default', function () {
    // Arrange
    Http::fake();

    config()->set('chronicle.channels.posthog.api_key', 'phc_test');

    // Act
    app(Chronicle::class)->record('ping');
    app()->terminate();

    // Assert
    expect(file_get_contents(chronicleLogPath()))->toContain('"type":"ping"');
    Http::assertNothingSent();
});

it('writes to the log file and to PostHog through the stack channel', function () {
    // Arrange
    Http::fake();

    config()->set('chronicle.channels.posthog.api_key', 'phc_test');

    config()->set('chronicle.default', 'stack');

    // Act
    app(Chronicle::class)->record('ping');
    app()->terminate();

    // Assert
    expect(file_get_contents(chronicleLogPath()))->toContain('"type":"ping"')
        ->and(posthogBatches()[0][0]['body'])->toBe(['stringValue' => 'ping']);
});
