<?php

use Illuminate\Support\Facades\Log;
use Larasell\Chronicle\Chronicle;
use Larasell\Chronicle\WideEvents\Outputs\Exceptions\MissingPostHogApiKey;
use Larasell\Chronicle\WideEvents\Outputs\PostHogHandler;
use Monolog\Level;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Logs\LogRecordBuilderInterface;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\InstrumentationScope\Configurator;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use RuntimeException;

function posthogTestProvider(array &$emitted): LoggerProviderInterface
{
    $otelLogger = new class($emitted) implements LoggerInterface
    {
        public function __construct(public array &$emitted) {}

        public function emit(LogRecord $record): void
        {
            $this->emitted[] = $record;
        }

        public function logRecordBuilder(): LogRecordBuilderInterface
        {
            throw new RuntimeException('not needed');
        }

        public function isEnabled(?ContextInterface $context = null, ?int $severityNumber = null, ?string $eventName = null): bool
        {
            return true;
        }
    };

    return new class($otelLogger) implements LoggerProviderInterface
    {
        public function __construct(protected LoggerInterface $logger) {}

        public function getLogger(string $name, ?string $version = null, ?string $schemaUrl = null, ?iterable $attributes = []): LoggerInterface
        {
            return $this->logger;
        }

        public function shutdown(): bool
        {
            return true;
        }

        public function forceFlush(): bool
        {
            return true;
        }

        public function updateConfigurator(?Configurator $configurator): void {}
    };
}

function posthogSeverityForLevel(string|int|Level $level): int
{
    $emitted = [];

    app()->instance(LoggerProviderInterface::class, posthogTestProvider($emitted));

    config()->set('logging.channels.chronicle', [
        'driver' => 'posthog',
        'api_key' => 'phc_test',
        'host' => 'https://us.i.posthog.com',
    ]);

    $level = $level instanceof Level ? $level : Level::fromName(ucfirst($level));

    Log::channel('chronicle')->log($level->getName(), json_encode(['type' => 'request']));

    $record = $emitted[0];
    $props = fn (string $name): mixed => (fn () => $this->{$name})->call($record);

    return $props('severityNumber');
}

it('emits chronicle entries as otel log records', function () {
    $emitted = [];

    $this->app->instance(LoggerProviderInterface::class, posthogTestProvider($emitted));

    config()->set('logging.channels.chronicle', [
        'driver' => 'posthog',
        'api_key' => 'phc_test',
        'host' => 'https://us.i.posthog.com',
    ]);

    Log::channel('chronicle')->info(json_encode([
        'timestamp' => '2026-10-07T20:00:00+00:00',
        'type' => 'request',
        'method' => 'GET',
        'status' => 200,
        'user.id' => 42,
    ]));

    expect($emitted)->toHaveCount(1);

    $record = $emitted[0];
    $props = fn (string $name): mixed => (fn () => $this->{$name})->call($record);

    expect($props('body'))->toBe('request')
        ->and($props('severityNumber'))->toBe(Severity::INFO->value)
        ->and($props('attributes'))->toMatchArray([
            'method' => 'GET',
            'status' => 200,
            'posthogDistinctId' => '42',
        ]);
});

it('maps monolog levels to otel severities', function (string|int|Level $level, int $expected) {
    expect(posthogSeverityForLevel($level))->toBe($expected);
})->with([
    ['info', Severity::INFO->value],
    ['warning', Severity::WARN->value],
    ['error', Severity::ERROR->value],
    [Level::Warning, Severity::WARN->value],
    [Level::Error, Severity::ERROR->value],
]);

it('maps entry fields to otel attributes', function () {
    $handler = new class('https://us.i.posthog.com', 'phc_test') extends PostHogHandler
    {
        /**
         * @return array<string, scalar>
         */
        public function exposedAttributes(array $entry): array
        {
            return $this->attributes($entry);
        }
    };

    $attributes = $handler->exposedAttributes([
        'timestamp' => 'now',
        'type' => 'request',
        'method' => 'GET',
        'status' => 200,
        'duration_ms' => 1.5,
        'user.id' => 42,
        'session.id' => 'sess_abc',
    ]);

    expect($attributes)->toMatchArray([
        'method' => 'GET',
        'status' => 200,
        'duration_ms' => 1.5,
        'posthogDistinctId' => '42',
        'sessionId' => 'sess_abc',
    ])->not->toHaveKeys(['timestamp', 'type']);
});

it('throws in debug mode when the posthog channel is enabled without an api key', function () {
    config()->set('app.debug', true);
    config()->set('chronicle.channels.posthog.api_key', null);
    config()->set('chronicle.default', 'posthog');

    app(Chronicle::class)->record('request');
})->throws(MissingPostHogApiKey::class, 'POSTHOG_API_KEY is required');

it('does not throw outside debug mode when the api key is missing', function () {
    config()->set('app.debug', false);
    config()->set('chronicle.channels.posthog.api_key', null);
    config()->set('chronicle.default', 'posthog');

    $record = fn () => app(Chronicle::class)->record('request');

    expect($record)->not->toThrow(MissingPostHogApiKey::class);
});

it('writes only to the log file by default', function () {
    $emitted = [];
    $this->app->instance(LoggerProviderInterface::class, posthogTestProvider($emitted));
    config()->set('chronicle.channels.posthog.api_key', 'phc_test');

    app(Chronicle::class)->record('ping');

    expect(file_get_contents(chronicleLogPath()))->toContain('"type":"ping"')
        ->and($emitted)->toBeEmpty();
});

it('writes to the log file and to PostHog through the stack channel', function () {
    $emitted = [];
    $this->app->instance(LoggerProviderInterface::class, posthogTestProvider($emitted));
    config()->set('chronicle.channels.posthog.api_key', 'phc_test');
    config()->set('chronicle.default', 'stack');

    app(Chronicle::class)->record('ping');

    expect(file_get_contents(chronicleLogPath()))->toContain('"type":"ping"')
        ->and($emitted)->toHaveCount(1);
});
