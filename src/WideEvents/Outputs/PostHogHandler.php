<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents\Outputs;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use JsonException;
use Larasell\Chronicle\WideEvents\Chronicle;
use Larasell\Chronicle\WideEvents\Outputs\Exceptions\InvalidChronicleEvent;
use Larasell\Chronicle\WideEvents\Outputs\Exceptions\MissingPostHogApiKey;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use stdClass;
use Throwable;

/**
 * Sends Chronicle's events to PostHog Logs as OTLP/HTTP JSON, the format PostHog's own SDKs send.
 *
 * Events are buffered and sent in one request per batch, never one request per event: when the
 * app terminates, after each queued job, and early once a batch reaches BATCH_EVENTS_MAX or
 * BATCH_BYTES_MAX. So a request pays for PostHog once, after its response, rather than on every
 * log call. A batch that can't be delivered within the limits below is dropped and reported to
 * PHP's error log: logging must never take the app down, and Chronicle's own channels may be what
 * failed, so it can't report through them.
 *
 * Worst case per batch: (1 + RETRIES) × TIMEOUT_SECONDS + RETRIES × RETRY_DELAY_MS, which is
 * 2 × 2 s + 100 ms = 4.1 s, spent once per batch.
 *
 * @internal
 */
class PostHogHandler extends AbstractProcessingHandler
{
    /** Events per request. Large enough to send a request's events at once, small enough to stay fast. */
    public const BATCH_EVENTS_MAX = 100;

    /**
     * Bytes of events per request, counted as the events' JSON. Chronicle bounds an event at
     * about 300 KB, so a batch always fits at least three of the largest.
     */
    public const BATCH_BYTES_MAX = 1_000_000;

    /** Seconds to wait for PostHog to accept the connection. */
    private const CONNECT_TIMEOUT_SECONDS = 1;

    /** Seconds to wait for PostHog to answer, connection included. */
    private const TIMEOUT_SECONDS = 2;

    /** Extra attempts after a failed one, for a blip in the network or at PostHog. */
    private const RETRIES = 1;

    private const RETRY_DELAY_MS = 100;

    /** @var list<array<string, mixed>> OTLP log records waiting to be sent. */
    private array $batch = [];

    private int $batchBytes = 0;

    public function __construct(
        protected string $host,
        protected string $apiKey,
        protected HttpFactory $http,
        // OTLP's service.name, which PostHog groups and filters logs by.
        protected string $service,
        protected string $scope = 'chronicle',
        int|string|Level $level = Level::Info,
        bool $bubble = true,
        protected bool $debug = false,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if ($this->apiKey === '') {
            // Without a key every event is dropped. Fail loudly in debug mode, so a developer
            // notices, but never take production down over a missing log destination.
            if ($this->debug) {
                throw MissingPostHogApiKey::forPostHogChannel();
            }

            return;
        }

        $entry = $this->entry($record->message);
        $entryBytes = strlen($record->message);

        if ($this->batchBytes + $entryBytes > self::BATCH_BYTES_MAX) {
            $this->flush();
        }

        $this->batch[] = $this->logRecord($entry, $record);
        $this->batchBytes += $entryBytes;

        if (count($this->batch) === self::BATCH_EVENTS_MAX) {
            $this->flush();
        }
    }

    /**
     * Sends the buffered events in one request and empties the buffer, delivered or not, so a
     * failing PostHog can't make it grow.
     */
    public function flush(): void
    {
        if ($this->batch === []) {
            return;
        }

        $batch = $this->batch;
        $this->batch = [];
        $this->batchBytes = 0;

        try {
            $response = $this->http
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->retry(1 + self::RETRIES, self::RETRY_DELAY_MS, throw: false)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->asJson()
                ->post(rtrim($this->host, '/').'/i/v1/logs', $this->payload($batch));
        } catch (ConnectionException $exception) {
            $this->reportDropped(count($batch), $exception->getMessage());

            return;
        }

        if ($response->failed()) {
            $this->reportDropped(count($batch), 'PostHog answered HTTP '.$response->status());
        }
    }

    /**
     * Monolog closes handlers when their logger is closed or destroyed, the last chance to send.
     * A destructor must not throw, so anything flush() lets through is reported instead.
     */
    public function close(): void
    {
        try {
            $this->flush();
        } catch (Throwable $throwable) {
            error_log('Chronicle could not send its last events to PostHog: '.$throwable->getMessage());
        }

        parent::close();
    }

    private function reportDropped(int $eventCount, string $reason): void
    {
        error_log(sprintf('Chronicle dropped %d events for PostHog: %s', $eventCount, $reason));
    }

    /**
     * Decodes a message into the event Chronicle encoded. Chronicle::write() only writes JSON
     * objects, so anything else was written to this channel some other way. That fails loudly in
     * debug mode, like a missing key. In production it is sent as it is, with the reason it
     * couldn't be read, since a log line we can't parse is still better than one we drop.
     *
     * @return array<mixed>
     */
    protected function entry(string $message): array
    {
        // Decoded as objects, not arrays, so a JSON list can't pass for an event: its integer
        // keys would make invalid attribute names.
        try {
            $entry = json_decode($message, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return $this->undecodableEntry($message, $exception->getMessage());
        }

        if ($entry instanceof stdClass) {
            return get_object_vars($entry);
        }

        return $this->undecodableEntry($message, 'it decodes to '.get_debug_type($entry));
    }

    /**
     * @return array<string, string>
     */
    protected function undecodableEntry(string $message, string $reason): array
    {
        if ($this->debug) {
            throw InvalidChronicleEvent::forPostHogChannel($reason);
        }

        return [
            'type' => 'log',
            'message' => mb_strcut($message, 0, Chronicle::VALUE_BYTES_MAX, 'UTF-8'),
            'chronicle.decode_error' => $reason,
        ];
    }

    /**
     * One OTLP log record: the event's type as its body, and every other field as an attribute.
     *
     * @param  array<mixed>  $entry
     * @return array<string, mixed>
     */
    private function logRecord(array $entry, LogRecord $record): array
    {
        [$severityNumber, $severityText] = $this->severity($record->level);
        // OTLP wants nanoseconds as a decimal string; Monolog's clock has microseconds.
        $timeUnixNano = $record->datetime->format('Uu').'000';
        $type = $entry['type'] ?? null;

        return [
            'timeUnixNano' => $timeUnixNano,
            'observedTimeUnixNano' => $timeUnixNano,
            'severityNumber' => $severityNumber,
            'severityText' => $severityText,
            'body' => ['stringValue' => is_string($type) ? $type : 'log'],
            'attributes' => $this->otlpAttributes($this->attributes($entry)),
        ];
    }

    /**
     * The OTLP severity number and text: DEBUG 5, INFO 9, WARN 13, ERROR 17, FATAL 21. OTLP has
     * no notice, so it is INFO, and critical, alert and emergency are all FATAL.
     *
     * @return array{int, string}
     */
    protected function severity(Level $level): array
    {
        return match ($level) {
            Level::Emergency, Level::Alert, Level::Critical => [21, 'FATAL'],
            Level::Error => [17, 'ERROR'],
            Level::Warning => [13, 'WARN'],
            Level::Notice, Level::Info => [9, 'INFO'],
            Level::Debug => [5, 'DEBUG'],
        };
    }

    /**
     * The event's fields as attributes. posthogDistinctId and sessionId are the names PostHog
     * links a log line to a person and a session replay by.
     *
     * @param  array<mixed>  $entry
     * @return array<scalar|null>
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

        $userId = $entry['user.id'] ?? null;
        $sessionId = $entry['session.id'] ?? null;

        if (is_scalar($userId)) {
            $attributes['posthogDistinctId'] = (string) $userId;
        }

        if (is_scalar($sessionId)) {
            $attributes['sessionId'] = (string) $sessionId;
        }

        return $attributes;
    }

    /**
     * Attributes as an OTLP key/value list. Null values are left out, since OTLP has no null.
     * Integers are decimal strings, as OTLP's JSON encoding wants for 64-bit integers.
     *
     * @param  array<scalar|null>  $attributes
     * @return list<array{key: string, value: array<string, bool|float|string>}>
     */
    private function otlpAttributes(array $attributes): array
    {
        $list = [];

        foreach ($attributes as $key => $value) {
            $otlpValue = match (true) {
                is_string($value) => ['stringValue' => $value],
                is_int($value) => ['intValue' => (string) $value],
                is_float($value) => ['doubleValue' => $value],
                is_bool($value) => ['boolValue' => $value],
                default => null,
            };

            if ($otlpValue !== null) {
                $list[] = ['key' => (string) $key, 'value' => $otlpValue];
            }
        }

        return $list;
    }

    /**
     * An OTLP ExportLogsServiceRequest holding the batch.
     *
     * @param  list<array<string, mixed>>  $batch
     * @return array<string, mixed>
     */
    private function payload(array $batch): array
    {
        return [
            'resourceLogs' => [[
                'resource' => [
                    'attributes' => [['key' => 'service.name', 'value' => ['stringValue' => $this->service]]],
                ],
                'scopeLogs' => [[
                    'scope' => ['name' => $this->scope],
                    'logRecords' => $batch,
                ]],
            ]],
        ];
    }
}
