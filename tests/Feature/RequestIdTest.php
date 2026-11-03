<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Larasell\Chronicle\WideEvents\Requests\Middleware\LogRequests;
use Larasell\Chronicle\WideEvents\Requests\RequestId;

// As apps should register it: first of all global middleware.
beforeEach(function () {
    $this->app->make(Kernel::class)->prependMiddleware(LogRequests::class);
});

function isUuidV7(?string $value): bool
{
    return $value !== null && Str::isUuid($value) && $value[14] === '7';
}

it('assigns every request a UUIDv7, sends it back and logs it', function () {
    $handler = chronicleTestHandler();
    Route::get('/ping', fn () => 'ok');

    $id = $this->get('/ping')->assertOk()->headers->get('X-Request-Id');

    expect(isUuidV7($id))->toBeTrue()
        ->and(chronicleEntries($handler)[0]['request.id'])->toBe($id);
});

it('assigns the id before the request is handled, so every log line of the request carries it', function () {
    $handler = chronicleTestHandler();
    Route::get('/work', function () {
        Log::channel('app_test')->info('working');

        return RequestId::current();
    });

    $response = $this->get('/work');
    $id = $response->headers->get('X-Request-Id');

    expect($response->getContent())->toBe($id)
        ->and($handler->getRecords()[0]->extra['request.id'] ?? null)->toBe($id);
});

it('assigns ids to requests no route matches', function () {
    $response = $this->get('/does-not-exist')->assertNotFound();

    expect(isUuidV7($response->headers->get('X-Request-Id')))->toBeTrue();
});

it('gives every request its own id', function () {
    Route::get('/ping', fn () => 'ok');

    expect($this->get('/ping')->headers->get('X-Request-Id'))
        ->not->toBe($this->get('/ping')->headers->get('X-Request-Id'));
});

it('keeps a well-formed id the client sends', function (string $id) {
    $handler = chronicleTestHandler();
    Route::get('/ping', fn () => RequestId::current());

    $response = $this->get('/ping', ['X-Request-Id' => $id]);

    expect($response->headers->get('X-Request-Id'))->toBe($id)
        ->and($response->getContent())->toBe($id)
        ->and(chronicleEntries($handler)[0]['request.id'])->toBe($id);
})->with([
    'a proxy id' => ['storefront-7f3a.42'],
    'one character' => ['a'],
    '200 characters' => [str_repeat('a', 200)],
]);

it('replaces malformed ids the client sends with its own', function (string $id) {
    Route::get('/ping', fn () => 'ok');

    expect(isUuidV7($this->get('/ping', ['X-Request-Id' => $id])->headers->get('X-Request-Id')))->toBeTrue();
})->with([
    'empty' => [''],
    '201 characters' => [str_repeat('a', 201)],
    'a space' => ['has space'],
    'not ASCII' => ['anfrage-ä'],
]);

it('has no current id outside a request', function () {
    expect(RequestId::current())->toBeNull();
});
