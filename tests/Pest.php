<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Larasell\Chronicle\Tests\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;

uses(TestCase::class)->in('Feature');

/**
 * Points Chronicle at the test channel TestCase defines and returns its handler, which is fresh
 * for every test, so a test reads exactly the events it wrote.
 */
function chronicleTestHandler(): TestHandler
{
    config()->set('chronicle.default', 'test');

    return app(TestHandler::class);
}

/**
 * Runs the rest of the test as production without debug mode, where Chronicle must never stop
 * the app: failed checks and malformed config are reported instead of thrown.
 */
function inProduction(): void
{
    config()->set('app.debug', false);
    app()->instance('env', 'production');
}

/**
 * The log file of this test's file channel, which TestCase makes fresh for every test.
 */
function chronicleLogPath(): string
{
    return Config::string('chronicle.channels.file.path');
}

/**
 * Decodes one Chronicle log line. Every line must be a JSON object, so anything else fails the test.
 *
 * @return array<mixed>
 */
function chronicleEntry(string $line): array
{
    $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($entry)) {
        throw new UnexpectedValueException("A Chronicle log line must be a JSON object, [{$line}] given.");
    }

    return $entry;
}

/**
 * @return array<array<mixed>>
 */
function chronicleEntries(TestHandler $handler): array
{
    return array_map(fn (LogRecord $record): array => chronicleEntry($record->message), $handler->getRecords());
}

/**
 * The bytes a string takes in a Chronicle log line, between its quotes: escapes included, unicode
 * and slashes unescaped, as Chronicle writes it.
 */
function lineBytes(string $value): int
{
    return strlen(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) - 2;
}
