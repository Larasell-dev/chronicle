<?php

namespace Larasell\Chronicle\Logging;

use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class PostHogHandler extends AbstractProcessingHandler
{
    public function __construct(
        protected string $host,
        protected string $apiKey,
        int|string|Level $level = Level::Info,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if ($this->apiKey === '') {
            return;
        }

        $entry = json_decode((string) $record->message, true);

        if (! is_array($entry)) {
            return;
        }

        $this->send($entry);
    }

    protected function send(array $entry): void
    {
        Http::timeout(5)
            ->withToken($this->apiKey)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post(rtrim($this->host, '/').'/i/v1/logs', [
                'resourceLogs' => [[
                    'resource' => [
                        'attributes' => $this->attributes([
                            'service.name' => config('chronicle.posthog.service', config('app.name')),
                            'deployment.environment' => config('app.env'),
                        ]),
                    ],
                    'scopeLogs' => [[
                        'scope' => [
                            'name' => 'chronicle',
                        ],
                        'logRecords' => [
                            $this->toLogRecord($entry),
                        ],
                    ]],
                ]],
            ]);
    }

    protected function toLogRecord(array $entry): array
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

        return [
            'timeUnixNano' => (string) now()->getPreciseTimestamp(9),
            'severityText' => 'INFO',
            'body' => ['stringValue' => $entry['type']],
            'attributes' => $this->attributes($attributes),
        ];
    }

    /**
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    protected function attributes(array $attributes): array
    {
        return collect($attributes)
            ->map(function (mixed $value, string|int $key): array {
                $type = match (true) {
                    is_int($value) => 'intValue',
                    is_float($value) => 'doubleValue',
                    is_bool($value) => 'boolValue',
                    default => 'stringValue',
                };

                return [
                    'key' => (string) $key,
                    'value' => [$type => $value],
                ];
            })
            ->values()
            ->all();
    }
}
