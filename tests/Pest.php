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
