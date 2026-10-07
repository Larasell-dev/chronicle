<?php

use Illuminate\Support\Facades\Log;
use Larasell\Chronicle\Chronicle;
use Larasell\Chronicle\Logging\PostHogHandler;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Logs\LogRecordBuilderInterface;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\InstrumentationScope\Configurator;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use RuntimeException;

it('emits chronicle entries as otel log records', function () {
    $emitted = [];

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

    $provider = new class($otelLogger) implements LoggerProviderInterface
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

    $this->app->instance(LoggerProviderInterface::class, $provider);

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
    config()->set('chronicle.posthog.enabled', true);
    config()->set('chronicle.posthog.api_key', null);
    config()->set('chronicle.channel', 'posthog');

    app(Chronicle::class)->record('request');
})->throws(RuntimeException::class, 'POSTHOG_API_KEY is required');

it('does not throw outside debug mode when the api key is missing', function () {
    config()->set('app.debug', false);
    config()->set('chronicle.posthog.enabled', true);
    config()->set('chronicle.posthog.api_key', null);
    config()->set('chronicle.channel', 'posthog');

    $record = fn () => app(Chronicle::class)->record('request');

    expect($record)->not->toThrow(RuntimeException::class);
});
