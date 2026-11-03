<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Larasell\Chronicle\Facades\Chronicle;
use Larasell\Chronicle\WideEvents\Requests\Middleware\LogRequests;
use Monolog\Level as MonologLevel;

it('records one event for a scope with the fields added in it and returns its result', function () {
    // Arrange
    $handler = chronicleTestHandler();

    // Act
    $result = Chronicle::scope('import.run', function (): int {
        Chronicle::add('import.rows', 120);
        Chronicle::add('duration_ms', -1);
        usleep(20_000);

        return 120;
    });

    // Assert
    $records = $handler->getRecords();
    $entry = chronicleEntry($records[0]->message);

    // The measured duration beats the one added: 20 ms of sleep, so never -1.
    expect($result)->toBe(120)
        ->and($records)->toHaveCount(1)
        ->and($records[0]->level)->toBe(MonologLevel::Info)
        ->and($entry['type'])->toBe('import.run')
        ->and($entry['import.rows'])->toBe(120)
        ->and($entry['duration_ms'])->toBeGreaterThanOrEqual(20.0);
});

it('records a scope that throws as an error event and throws on', function () {
    // Arrange
    $handler = chronicleTestHandler();

    // Act
    $thrown = null;

    try {
        Chronicle::scope('import.run', function (): void {
            Chronicle::add('import.rows', 3);

            throw new RuntimeException('disk full');
        });
    } catch (RuntimeException $exception) {
        $thrown = $exception;
    }

    // Assert
    $records = $handler->getRecords();
    $entry = chronicleEntry($records[0]->message);

    expect($thrown?->getMessage())->toBe('disk full')
        ->and($records)->toHaveCount(1)
        ->and($records[0]->level)->toBe(MonologLevel::Error)
        ->and($entry['type'])->toBe('import.run')
        ->and($entry['import.rows'])->toBe(3)
        ->and($entry['error.message'])->toBe('disk full');
});

it('adds fields to the innermost scope, the request being the outermost', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/import', function () {
        Chronicle::add('import.source', 'csv');

        Chronicle::scope('import.batch', function (): void {
            Chronicle::add('batch.rows', 50);
        });

        Chronicle::add('import.done', true);

        return 'ok';
    })->middleware(LogRequests::class);

    // Act
    $this->get('/import');

    // Assert
    [$batch, $request] = chronicleEntries($handler);

    expect($batch['type'])->toBe('import.batch')
        ->and($batch['batch.rows'])->toBe(50)
        ->and($batch)->not->toHaveKey('import.source')
        ->and($request['type'])->toBe('request')
        ->and($request['import.source'])->toBe('csv')
        ->and($request['import.done'])->toBeTrue()
        ->and($request)->not->toHaveKey('batch.rows');
});

it('logs a request made inside a scope with the fields of the request only', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/plain', function () {
        Chronicle::add('inner', 1);

        return 'ok';
    })->middleware(LogRequests::class);

    // Act
    Chronicle::scope('outer', function (): void {
        Chronicle::add('outer', 1);
        $this->get('/plain');
    });

    // Assert
    [$request, $outer] = chronicleEntries($handler);

    expect($request['inner'])->toBe(1)
        ->and($request)->not->toHaveKey('outer')
        ->and($outer['outer'])->toBe(1)
        ->and($outer)->not->toHaveKey('inner');
});
