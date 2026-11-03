<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Larasell\Chronicle\Facades\Chronicle;
use Larasell\Chronicle\Tests\Fixtures\ImportProducts;
use Larasell\Chronicle\WideEvents\Requests\Middleware\LogRequests;
use Monolog\Level as MonologLevel;

beforeEach(function () {
    config()->set('queue.default', 'sync');
});

it('records no job event while log jobs is off', function () {
    // Arrange
    $handler = chronicleTestHandler();

    // Act
    ImportProducts::dispatch();

    // Assert
    expect($handler->getRecords())->toBe([]);
});

it('records one job event per job with the fields added while it ran', function () {
    // Arrange
    config()->set('chronicle.log_jobs', true);
    $handler = chronicleTestHandler();

    // Act
    ImportProducts::dispatch();

    // Assert
    $records = $handler->getRecords();
    $entry = chronicleEntry($records[0]->message);

    expect($records)->toHaveCount(1)
        ->and($records[0]->level)->toBe(MonologLevel::Info)
        ->and($entry['type'])->toBe('job')
        ->and($entry['job.name'])->toBe(ImportProducts::class)
        ->and($entry['job.connection'])->toBe('sync')
        ->and($entry['job.attempt'])->toBe(1)
        ->and($entry['job.outcome'])->toBe('processed')
        ->and($entry['job.id'])->toBeString()
        ->and($entry['import.behavior'])->toBe('import')
        ->and($entry['import.done'])->toBeTrue()
        ->and($entry['duration_ms'])->toBeFloat();
});

it('records a failed attempt as an error event', function () {
    // Arrange
    config()->set('chronicle.log_jobs', true);
    $handler = chronicleTestHandler();

    // Act
    $thrown = null;

    try {
        ImportProducts::dispatch('fail');
    } catch (RuntimeException $exception) {
        $thrown = $exception;
    }

    // Assert
    $records = $handler->getRecords();
    $entry = chronicleEntry($records[0]->message);

    expect($thrown?->getMessage())->toBe('disk full')
        ->and($records)->toHaveCount(1)
        ->and($records[0]->level)->toBe(MonologLevel::Error)
        ->and($entry['job.outcome'])->toBe('failed')
        ->and($entry['error.message'])->toBe('disk full')
        ->and($entry['import.behavior'])->toBe('fail');
});

it('records a released job as released', function () {
    // Arrange
    config()->set('chronicle.log_jobs', true);
    $handler = chronicleTestHandler();

    // Act
    ImportProducts::dispatch('release');

    // Assert
    expect(chronicleEntries($handler)[0]['job.outcome'])->toBe('released');
});

it('gives a job that another job dispatched its own event', function () {
    // Arrange
    config()->set('chronicle.log_jobs', true);
    $handler = chronicleTestHandler();

    // Act
    ImportProducts::dispatch('dispatch');

    // Assert
    [$inner, $outer] = chronicleEntries($handler);

    expect($inner['import.behavior'])->toBe('import')
        ->and($outer['import.behavior'])->toBe('dispatch')
        ->and($inner['job.id'])->not->toBe($outer['job.id']);
});

it('keeps the fields of a job dispatched in a request out of the request event', function () {
    // Arrange
    config()->set('chronicle.log_jobs', true);
    $handler = chronicleTestHandler();

    Route::get('/import', function () {
        Chronicle::add('import.requested', true);
        ImportProducts::dispatch();

        return 'ok';
    })->middleware(LogRequests::class);

    // Act
    $response = $this->get('/import');

    // Assert
    [$job, $request] = chronicleEntries($handler);

    expect($job['type'])->toBe('job')
        ->and($job['import.done'])->toBeTrue()
        ->and($job)->not->toHaveKey('import.requested')
        ->and($job['request.id'])->toBe($response->headers->get('X-Request-Id'))
        ->and($request['import.requested'])->toBeTrue()
        ->and($request)->not->toHaveKey('import.done');
});

it('records job events in the fake', function () {
    // Arrange
    config()->set('chronicle.log_jobs', true);
    Chronicle::fake();

    // Act
    ImportProducts::dispatch();

    // Assert
    Chronicle::assertRecorded('job', fn (array $event): bool => $event['job.name'] === ImportProducts::class
        && $event['import.done'] === true);
});
