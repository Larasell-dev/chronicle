<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Larasell\Chronicle\WideEvents\Requests\Middleware\LogRequests;
use Larasell\Chronicle\WideEvents\Requests\RequestId;

// As apps should register it: first of all global middleware.
beforeEach(function () {
    $kernel = app(Kernel::class);

    if (! $kernel instanceof HttpKernel) {
        throw new UnexpectedValueException('The tests need an HTTP kernel that can prepend middleware.');
    }

    $kernel->prependMiddleware(LogRequests::class);
});

function isUuidV7(?string $value): bool
{
    if ($value === null) {
        return false;
    }

    if (! Str::isUuid($value)) {
        return false;
    }

    return $value[14] === '7';
}

it('assigns every request a UUIDv7, sends it back and logs it', function () {
    // Arrange
    $handler = chronicleTestHandler();
    Route::get('/ping', fn () => 'ok');

    // Act
    $id = $this->get('/ping')->assertOk()->headers->get('X-Request-Id');

    // Assert
    expect(isUuidV7($id))->toBeTrue()
        ->and(chronicleEntries($handler)[0]['request.id'])->toBe($id);
});

it('assigns the id before the request is handled, so every log line of the request carries it', function () {
    // Arrange
    $handler = chronicleTestHandler();
    Route::get('/work', function () {
        Log::channel('app_test')->info('working');

        return RequestId::current();
    });

    // Act
    $response = $this->get('/work');
    $id = $response->headers->get('X-Request-Id');

    // Assert
    expect($response->getContent())->toBe($id)
        ->and($handler->getRecords()[0]->extra['request.id'] ?? null)->toBe($id);
});

it('assigns ids to requests no route matches', function () {
    // Act
    $response = $this->get('/does-not-exist')->assertNotFound();

    // Assert
    expect(isUuidV7($response->headers->get('X-Request-Id')))->toBeTrue();
});

it('gives every request its own id', function () {
    // Arrange
    Route::get('/ping', fn () => 'ok');

    // Act & Assert
    expect($this->get('/ping')->headers->get('X-Request-Id'))
        ->not->toBe($this->get('/ping')->headers->get('X-Request-Id'));
});

it('keeps a well-formed id the client sends', function (string $id) {
    // Arrange
    $handler = chronicleTestHandler();
    Route::get('/ping', fn () => RequestId::current());

    // Act
    $response = $this->get('/ping', ['X-Request-Id' => $id]);

    // Assert
    expect($response->headers->get('X-Request-Id'))->toBe($id)
        ->and($response->getContent())->toBe($id)
        ->and(chronicleEntries($handler)[0]['request.id'])->toBe($id);
})->with([
    'a proxy id' => ['storefront-7f3a.42'],
    'one character' => ['a'],
    '200 characters' => [str_repeat('a', 200)],
]);

it('replaces malformed ids the client sends with its own', function (string $id) {
    // Arrange
    Route::get('/ping', fn () => 'ok');

    // Act & Assert
    expect(isUuidV7($this->get('/ping', ['X-Request-Id' => $id])->headers->get('X-Request-Id')))->toBeTrue();
})->with([
    'empty' => [''],
    '201 characters' => [str_repeat('a', 201)],
    'a space' => ['has space'],
    'not ASCII' => ['anfrage-ä'],
]);

it('has no current id outside a request', function () {
    // Act & Assert
    expect(RequestId::current())->toBeNull();
});
