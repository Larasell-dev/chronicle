<?php

use Illuminate\Support\Facades\Route;
use Larasell\Chronicle\Http\Middleware\LogRequests;

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
