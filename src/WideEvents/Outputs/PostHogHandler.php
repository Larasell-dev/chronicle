<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents\Outputs;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use JsonException;
use Larasell\Chronicle\Support\Assert;
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
    /**
     * The defaults of a posthog channel's options. config/chronicle.php and the posthog driver
     * both read them, so an app that published the config before an option existed gets the
     * same default as a fresh install.
     */
    public const HOST_DEFAULT = 'https://us.i.posthog.com';

    public const LEVEL_DEFAULT = 'info';

    public const SCOPE_DEFAULT = 'chronicle';

    /** Events per request. Large enough to send a request's events at once, small enough to stay fast. */
    public const BATCH_EVENTS_MAX = 100;

    /**
     * Bytes of events per request, counted as the events' JSON. Chronicle bounds an event's JSON at
     * Chronicle::EVENT_BYTES_MAX, about 311 KB, so a batch always fits at least three of the largest.
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

    /** The handler lives as long as the process, so this warns about a missing key once per process. */
    private bool $missingApiKeyWarned = false;

    /** Events dropped for a missing API key since the last flush(), which reports them. */
    private int $droppedWithoutApiKeyCount = 0;

    /**
     * @param  array<string, scalar|null>  $resource
     */
    public function __construct(
        protected string $host,
        protected string $apiKey,
        protected HttpFactory $http,
        // The OTLP resource attributes: Chronicle's global fields, such as service.name, which
        // PostHog groups and filters logs by.
        protected array $resource,
        protected string $scope,
        int|string|Level $level,
        bool $bubble = true,
        protected bool $debug = false,
    ) {
        parent::__construct($level, $bubble);

        // The other side of Chronicle's GLOBAL_FIELDS_MAX: the resource goes out with every batch.
        Assert::that(
            count($resource) <= Chronicle::GLOBAL_FIELDS_MAX,
            'chronicle.assert.global-fields',
            'The PostHog resource holds '.count($resource).' attributes, more than GLOBAL_FIELDS_MAX ('.Chronicle::GLOBAL_FIELDS_MAX.')',
        );
    }

    protected function write(LogRecord $record): void
    {
        if ($this->apiKey === '') {
            // Without a key every event is dropped. Fail loudly in debug mode, so a developer
            // notices, but never take production down over a missing log destination. There,
            // warn once, not on every event, and flush() reports how many were dropped.
            if ($this->debug) {
                throw MissingPostHogApiKey::forPostHogChannel();
            }

            if ($this->missingApiKeyWarned === false) {
                error_log("Chronicle's posthog channel has no POSTHOG_API_KEY; its events are dropped. Set the key or remove posthog from CHRONICLE_CHANNEL.");
                $this->missingApiKeyWarned = true;
            }

            $this->droppedWithoutApiKeyCount++;

            return;
        }

        $entry = $this->entry($record->message);
        $entryBytes = strlen($record->message);

        if ($this->batchBytes + $entryBytes > self::BATCH_BYTES_MAX) {
            $this->flush();
        }

        $this->batch[] = $this->logRecord($entry, $record);
        $this->batchBytes += $entryBytes;
        $this->assertBatchBounds(count($this->batch), $this->batchBytes);

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
        if ($this->droppedWithoutApiKeyCount > 0) {
            $this->reportDropped($this->droppedWithoutApiKeyCount, 'no API key');
            $this->droppedWithoutApiKeyCount = 0;
        }

        if ($this->batch === []) {
            return;
        }

        $batch = $this->batch;
        $this->assertBatchBounds(count($batch), $this->batchBytes);
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

    /**
     * write() flushes before a batch could pass either limit, and flush() checks again before it
     * sends. One event larger than BATCH_BYTES_MAX on its own still goes out, alone, since
     * dropping it would lose it, so the byte limit only holds once a batch has two.
     */
    private function assertBatchBounds(int $eventCount, int $batchBytes): void
    {
        Assert::that(
            $eventCount <= self::BATCH_EVENTS_MAX,
            'chronicle.assert.batch-events',
            "A PostHog batch holds {$eventCount} events, more than BATCH_EVENTS_MAX (".self::BATCH_EVENTS_MAX.')',
        );

        if ($eventCount > 1) {
            Assert::that(
                $batchBytes <= self::BATCH_BYTES_MAX,
                'chronicle.assert.batch-bytes',
                "A PostHog batch of {$eventCount} events holds {$batchBytes} bytes, more than BATCH_BYTES_MAX (".self::BATCH_BYTES_MAX.')',
            );
        }
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
     * The event's fields as attributes. A global field the resource already holds is left out,
     * unless the event gives it a value of its own, e.g. from Context. posthogDistinctId and
     * sessionId are the names PostHog links a log line to a person and a session replay by.
     *
     * @param  array<mixed>  $entry
     * @return array<scalar|null>
     */
    protected function attributes(array $entry): array
    {
        $attributes = collect($entry)
            ->except(['timestamp', 'type'])
            ->reject(fn (mixed $value, int|string $key): bool => $this->inResource($key, $value))
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
     * Whether the resource already holds the field with this value, so the record needn't.
     */
    private function inResource(int|string $key, mixed $value): bool
    {
        if (! array_key_exists($key, $this->resource)) {
            return false;
        }

        return $this->resource[$key] === $value;
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
                    'attributes' => $this->otlpAttributes($this->resource),
                ],
                'scopeLogs' => [[
                    'scope' => ['name' => $this->scope],
                    'logRecords' => $batch,
                ]],
            ]],
        ];
    }
}
