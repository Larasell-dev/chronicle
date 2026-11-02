<?php

namespace Larasell\Chronicle\Outputs;

use Larasell\Chronicle\ChronicleException;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord as MonologRecord;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;

class PostHogHandler extends AbstractProcessingHandler
{
    protected ?LoggerInterface $logger = null;

    public function __construct(
        protected string $host,
        protected string $apiKey,
        protected string $scope = 'chronicle',
        int|string|Level $level = Level::Info,
        bool $bubble = true,
        protected ?LoggerProviderInterface $provider = null,
        protected bool $debug = false,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(MonologRecord $record): void
    {
        if ($this->apiKey === '') {
            // Without a key every event is dropped. Fail loudly in debug mode, so a developer
            // notices, but never take production down over a missing log destination.
            if ($this->debug) {
                throw ChronicleException::make(
                    code: 'chronicle.posthog.missing-api-key',
                    message: 'POSTHOG_API_KEY is required by chronicle when the posthog channel is enabled, this causes logs to be silently missed. This error stops appearing once POSTHOG_API_KEY is configured',
                    why: 'The posthog channel is enabled but no API key is configured, so events cannot be delivered',
                    fix: 'Set POSTHOG_API_KEY in your environment or disable the channel via CHRONICLE_POSTHOG_ENABLED=false',
                    link: 'https://github.com/larasell-dev/chronicle#posthog',
                );
            }

            return;
        }

        $entry = json_decode((string) $record->message, true);

        if (! is_array($entry)) {
            return;
        }

        $logger = $this->otlpLogger();

        if ($logger === null) {
            return;
        }

        $logger->emit(
            (new LogRecord($entry['type'] ?? 'log'))
                ->setSeverityNumber($this->severityFor($record->level)->value)
                ->setAttributes($this->attributes($entry)),
        );
    }

    protected function severityFor(Level $level): Severity
    {
        return match ($level) {
            Level::Emergency, Level::Alert, Level::Critical => Severity::FATAL,
            Level::Error => Severity::ERROR,
            Level::Warning => Severity::WARN,
            Level::Notice => Severity::INFO,
            Level::Info => Severity::INFO,
            Level::Debug => Severity::DEBUG,
        };
    }

    protected function otlpLogger(): ?LoggerInterface
    {
        if ($this->logger !== null) {
            return $this->logger;
        }

        $provider = $this->provider ?? $this->buildProvider();

        return $this->logger = $provider->getLogger($this->scope);
    }

    protected function buildProvider(): LoggerProviderInterface
    {
        $transport = (new OtlpHttpTransportFactory)->create(
            rtrim($this->host, '/').'/i/v1/logs',
            'application/x-protobuf',
            ['Authorization' => 'Bearer '.$this->apiKey],
        );

        return LoggerProvider::builder()
            ->addLogRecordProcessor(new SimpleLogRecordProcessor(new LogsExporter($transport)))
            ->build();
    }

    /**
     * @return array<string, scalar>
     */
    protected function attributes(array $entry): array
    {
        $attributes = collect($entry)
            ->except(['timestamp', 'type'])
            ->map(fn ($value) => is_scalar($value) || $value === null ? $value : json_encode($value))
            ->all();

        if (isset($entry['user.id']) && $entry['user.id'] !== null) {
            $attributes['posthogDistinctId'] = (string) $entry['user.id'];
        }

        if (isset($entry['session.id']) && $entry['session.id'] !== null) {
            $attributes['sessionId'] = (string) $entry['session.id'];
        }

        return $attributes;
    }
}
