<?php

namespace Larasell\Chronicle\WideEvents\Outputs;

use Larasell\Chronicle\WideEvents\Outputs\Exceptions\MissingPostHogApiKey;
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

/**
 * @internal
 */
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
                throw MissingPostHogApiKey::forPostHogChannel();
            }

            return;
        }

        $entry = json_decode((string) $record->message, true);

        // Chronicle writes every event as one JSON object. Any other message was logged to this
        // channel by other code and has no type or fields to send, so it is skipped.
        if (is_array($entry)) {
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
    }

    /**
     * Monolog has eight levels and OpenTelemetry six severity groups. OpenTelemetry has no notice
     * level, so notice shares INFO with info, and everything above error is FATAL.
     */
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

    /**
     * Built on the first write, so a channel that never logs never builds a transport, and cached,
     * so every event of the process shares one provider.
     */
    protected function otlpLogger(): ?LoggerInterface
    {
        if ($this->logger !== null) {
            return $this->logger;
        }

        $provider = $this->provider ?? $this->buildProvider();

        return $this->logger = $provider->getLogger($this->scope);
    }

    /**
     * Sends OTLP over HTTP to PostHog's log endpoint. SimpleLogRecordProcessor exports each record
     * as soon as it is emitted, so no event waits in a buffer that is lost when a PHP worker exits
     * without flushing. The cost is one HTTP request per event.
     */
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
     * The type is already the record's body and the record carries its own time, so neither is
     * repeated as an attribute. OTLP attributes must be scalars, so anything else is sent as JSON.
     * posthogDistinctId and sessionId are the names PostHog reads to link a log to a person and a
     * session.
     *
     * @return array<string, scalar>
     */
    protected function attributes(array $entry): array
    {
        $attributes = collect($entry)
            ->except(['timestamp', 'type'])
            ->map(function ($value) {
                if ($value === null) {
                    return null;
                }

                if (is_scalar($value)) {
                    return $value;
                }

                return json_encode($value);
            })
            ->all();

        // isset() is false for null, so an event without a user or session links to neither.
        if (isset($entry['user.id'])) {
            $attributes['posthogDistinctId'] = (string) $entry['user.id'];
        }

        if (isset($entry['session.id'])) {
            $attributes['sessionId'] = (string) $entry['session.id'];
        }

        return $attributes;
    }
}
