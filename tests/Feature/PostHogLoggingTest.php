<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('sends entries as otlp log records through the posthog channel', function () {
    config()->set('chronicle.posthog.api_key', 'phc_test');
    config()->set('chronicle.posthog.host', 'https://us.i.posthog.com');
    config()->set('logging.channels.chronicle', ['driver' => 'posthog']);

    Http::fake();

    Log::channel('chronicle')->info(json_encode([
        'timestamp' => '2026-10-07T20:00:00+00:00',
        'type' => 'request',
        'request_id' => 'abc123',
        'method' => 'GET',
        'status' => 200,
        'duration_ms' => 1.5,
        'user.id' => 42,
    ]));

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://us.i.posthog.com/i/v1/logs') {
            return false;
        }

        if ($request->header('Authorization')[0] !== 'Bearer phc_test') {
            return false;
        }

        $payload = $request->data();

        $resource = collect($payload['resourceLogs'][0]['resource']['attributes'])
            ->pluck('value.stringValue', 'key');

        if ($resource['deployment.environment'] !== 'testing') {
            return false;
        }

        $record = $payload['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];

        if ($record['body']['stringValue'] !== 'request' || $record['severityText'] !== 'INFO') {
            return false;
        }

        $attributes = collect($record['attributes'])->pluck('value', 'key');

        return $attributes['posthogDistinctId']['stringValue'] === '42'
            && $attributes['status']['intValue'] === 200
            && $attributes['duration_ms']['doubleValue'] === 1.5
            && $attributes['method']['stringValue'] === 'GET';
    });
});

it('does not send without an api key', function () {
    config()->set('chronicle.posthog.api_key', null);
    config()->set('logging.channels.chronicle', ['driver' => 'posthog']);

    Http::fake();

    Log::channel('chronicle')->info('{"type":"request"}');

    Http::assertNothingSent();
});

it('writes to both file and posthog when stacked', function () {
    config()->set('chronicle.posthog.api_key', 'phc_test');
    config()->set('chronicle.posthog.host', 'https://us.i.posthog.com');
    config()->set('logging.channels.chronicle', [
        'driver' => 'stack',
        'channels' => ['chronicle.file', 'posthog'],
    ]);

    Http::fake();

    Log::channel('chronicle')->info('{"type":"request","status":200}');

    expect(file_get_contents(storage_path('logs/chronicle.log')))->toContain('"type":"request"');

    Http::assertSent(fn ($request) => $request->url() === 'https://us.i.posthog.com/i/v1/logs');
});
