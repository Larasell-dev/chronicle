<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Larasell\Chronicle\Chronicle;
use Larasell\Chronicle\ChronicleException;
use Larasell\Chronicle\Http\Middleware\LogRequests;
use Larasell\Chronicle\RequestLogger;
use Larasell\Chronicle\Tests\Fixtures\NotASampler;
use Larasell\Chronicle\Tests\Fixtures\RecordingSampler;
use Larasell\Chronicle\Tests\Fixtures\SuccessfulRequestsSampler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function chronicleTestHandler(): TestHandler
{
    config()->set('chronicle.enabled', true);
    config()->set('chronicle.channel', 'chronicle_test');
    config()->set('logging.channels.chronicle_test', [
        'driver' => 'monolog',
        'handler' => TestHandler::class,
    ]);

    $handler = Log::channel('chronicle_test')->getHandlers()[0];

    expect($handler)->toBeInstanceOf(TestHandler::class);

    return $handler;
}

function chronicleEntries(TestHandler $handler): array
{
    return array_map(
        fn (LogRecord $record) => json_decode($record->message, true),
        $handler->getRecords(),
    );
}

it('writes one structured json entry per request', function () {
    config()->set('chronicle.enabled', true);

    @unlink(storage_path('logs/chronicle.log'));

    Route::post('/login', fn () => 'ok')->middleware(LogRequests::class);

    $this->post('/login', ['email' => 'nils@example.com']);

    $log = file_get_contents(storage_path('logs/chronicle.log'));

    expect($log)->toBeString();

    $entry = json_decode(trim(explode("\n", trim($log))[0]), true);

    expect($entry)->toBeArray()
        ->and($entry['type'])->toBe('request')
        ->and($entry['method'])->toBe('POST')
        ->and($entry['url'])->toBe('http://localhost/login')
        ->and($entry['status'])->toBe(200)
        ->and($entry['duration_ms'])->toBeFloat()
        ->and($entry['ip'])->toBe('127.0.0.1')
        ->and($entry['user.id'])->toBeNull()
        ->and($entry['timestamp'])->toBeString();
});

it('does not log when disabled', function () {
    config()->set('chronicle.enabled', false);

    @unlink(storage_path('logs/chronicle.log'));

    Route::get('/ping', fn () => 'ok')->middleware(LogRequests::class);

    $this->get('/ping');

    expect(file_exists(storage_path('logs/chronicle.log')))->toBeFalse();
});

it('logs 2xx responses at info level', function () {
    $handler = chronicleTestHandler();

    Route::get('/ok', fn () => 'ok')->middleware(LogRequests::class);

    $this->get('/ok');

    expect($handler->getRecords())->toHaveCount(1)
        ->and($handler->getRecords()[0]->level)->toBe(Level::Info);
});

it('logs 3xx responses at info level', function () {
    $handler = chronicleTestHandler();

    Route::get('/redirect', fn () => redirect('/ok'))->middleware(LogRequests::class);

    $this->get('/redirect');

    expect($handler->getRecords())->toHaveCount(1)
        ->and($handler->getRecords()[0]->level)->toBe(Level::Info);
});

it('logs 4xx responses at warning level', function () {
    $handler = chronicleTestHandler();

    Route::get('/not-found', fn () => abort(404))->middleware(LogRequests::class);

    $this->get('/not-found');

    expect($handler->getRecords())->toHaveCount(1)
        ->and($handler->getRecords()[0]->level)->toBe(Level::Warning);
});

it('logs 5xx responses at error level', function () {
    $handler = chronicleTestHandler();

    Route::get('/boom', fn () => throw new RuntimeException('boom'))->middleware(LogRequests::class);

    $this->get('/boom');

    $records = array_values(array_filter(
        $handler->getRecords(),
        fn (LogRecord $record) => (json_decode($record->message, true)['type'] ?? null) === 'request',
    ));

    expect($records)->toHaveCount(1)
        ->and($records[0]->level)->toBe(Level::Error);
});

it('logs a failed request as a single event with the exception merged', function () {
    $handler = chronicleTestHandler();

    Route::get('/boom', fn () => throw new RuntimeException('boom'))->middleware(LogRequests::class);

    $this->get('/boom')->assertStatus(500);

    expect($handler->getRecords())->toHaveCount(1);

    $entry = chronicleEntries($handler)[0];

    expect($entry['type'])->toBe('request')
        ->and($entry['status'])->toBe(500)
        ->and($entry['error.code'])->toBeNull()
        ->and($entry['error.message'])->toBe('boom')
        ->and($entry['error.class'])->toBe(RuntimeException::class)
        ->and($entry['error.file'])->toBeString();
});

it('logs structured exception fields on failed requests', function () {
    $handler = chronicleTestHandler();

    Route::get('/boom', fn () => throw ChronicleException::make(
        code: 'billing.declined',
        message: 'Payment failed',
        why: 'Issuer declined the charge',
        fix: 'Try a different card',
    ))->middleware(LogRequests::class);

    $this->get('/boom')->assertStatus(500);

    $entry = chronicleEntries($handler)[0];

    expect($entry['error.code'])->toBe('billing.declined')
        ->and($entry['error.why'])->toBe('Issuer declined the charge')
        ->and($entry['error.fix'])->toBe('Try a different card');
});

it('logs http exceptions with their status on failed requests', function () {
    $handler = chronicleTestHandler();

    Route::get('/missing', fn () => abort(404))->middleware(LogRequests::class);

    $this->get('/missing')->assertStatus(404);

    $entry = chronicleEntries($handler)[0];

    expect($entry['status'])->toBe(404)
        ->and($entry['error.status'])->toBe(404)
        ->and($entry['error.class'])->toBe(NotFoundHttpException::class);
});

it('accepts an explicit level in chronicle record', function () {
    $handler = chronicleTestHandler();

    app(Chronicle::class)->record('request', ['status' => 200], Level::Warning);

    expect($handler->getRecords())->toHaveCount(1)
        ->and($handler->getRecords()[0]->level)->toBe(Level::Warning)
        ->and(chronicleEntries($handler)[0]['status'])->toBe(200);
});

it('drops request logs when the sampler returns false', function () {
    $handler = chronicleTestHandler();

    config()->set('chronicle.sampling', SuccessfulRequestsSampler::class);

    Route::get('/ok', fn () => 'ok')->middleware(LogRequests::class);
    Route::get('/missing', fn () => abort(404))->middleware(LogRequests::class);

    $this->get('/ok');
    $this->get('/missing');

    expect($handler->getRecords())->toHaveCount(1)
        ->and(chronicleEntries($handler)[0]['status'])->toBe(200);
});

it('keeps every request log when no sampler is configured', function () {
    $handler = chronicleTestHandler();

    Route::get('/ping', fn () => 'ok')->middleware(LogRequests::class);

    $this->get('/ping');

    expect($handler->getRecords())->toHaveCount(1);
});

it('passes request, response and payload to the sampler', function () {
    $handler = chronicleTestHandler();

    config()->set('chronicle.sampling', RecordingSampler::class);

    Route::get('/ping', fn () => 'ok')->middleware(LogRequests::class);

    $this->get('/ping');

    expect($handler->getRecords())->toHaveCount(1)
        ->and(RecordingSampler::$seen)->toBe(['GET', 200, 'http://localhost/ping']);
});

it('throws when the configured sampler does not implement the interface', function () {
    config()->set('chronicle.sampling', NotASampler::class);

    app(RequestLogger::class)->log(request(), response('ok'), microtime(true));
})->throws(ChronicleException::class, 'chronicle.sampling must resolve to an instance of Larasell\Chronicle\Sampler');
