<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Testing;

use Closure;
use Larasell\Chronicle\WideEvents\Chronicle;
use Larasell\Chronicle\WideEvents\Enums\Level;
use Larasell\Chronicle\WideEvents\UnencodableValue;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Chronicle for tests, which Chronicle::fake() puts in place of the real one. It builds every event
 * exactly like Chronicle, with the same precedence, redaction and limits, but keeps the finished
 * events instead of writing them, so a test reads what production would log and writes nothing.
 * It also keeps every field add() gets, also outside a scope, so a test of a single class can
 * check what it adds to the event of whatever request or scope will run it.
 */
final class ChronicleFake extends Chronicle
{
    /**
     * Every event recorded since fake(), in order, with its level.
     *
     * @var list<array{event: array<string, mixed>, level: Level}>
     */
    private array $events = [];

    /**
     * Every field add() got since fake(), flattened and redacted as in an event, a later value of
     * a key replacing an earlier one.
     *
     * @var array<string, mixed>
     */
    private array $addedFields = [];

    public function __construct(Chronicle $chronicle)
    {
        parent::__construct($chronicle->redactedKeys, $chronicle->redactor, $chronicle->globalFields);
    }

    public function add(string $key, mixed $value): void
    {
        parent::add($key, $value);

        foreach ($this->flatten([$key => $value]) as $field => $flattened) {
            $this->addedFields[$field] = $flattened instanceof UnencodableValue ? $flattened->replacement : $flattened;
        }
    }

    /**
     * The recorded events, in order, optionally only those of a type that the callback accepts.
     *
     * @param  ?Closure(array<string, mixed>, Level): bool  $callback
     * @return list<array<string, mixed>>
     */
    public function recorded(?string $type = null, ?Closure $callback = null): array
    {
        $events = [];

        foreach ($this->events as ['event' => $event, 'level' => $level]) {
            if ($type !== null && $event['type'] !== $type) {
                continue;
            }

            if ($callback !== null && ! $callback($event, $level)) {
                continue;
            }

            $events[] = $event;
        }

        return $events;
    }

    /**
     * The fields add() got, flattened and redacted as in an event.
     *
     * @return array<string, mixed>
     */
    public function added(): array
    {
        return $this->addedFields;
    }

    /**
     * @param  ?Closure(array<string, mixed>, Level): bool  $callback
     */
    public function assertRecorded(string $type, ?Closure $callback = null): void
    {
        $matching = $callback === null ? '' : ' that matches the callback';

        PHPUnit::assertNotEmpty(
            $this->recorded($type, $callback),
            "Chronicle recorded no [{$type}] event{$matching}. {$this->summary()}",
        );
    }

    public function assertRecordedTimes(string $type, int $times): void
    {
        $count = count($this->recorded($type));

        PHPUnit::assertSame($times, $count, "Chronicle recorded [{$type}] {$count} times, not {$times}.");
    }

    /**
     * @param  ?Closure(array<string, mixed>, Level): bool  $callback
     */
    public function assertNotRecorded(string $type, ?Closure $callback = null): void
    {
        $matching = $callback === null ? '' : ' that matches the callback';

        PHPUnit::assertEmpty(
            $this->recorded($type, $callback),
            "Chronicle recorded a [{$type}] event{$matching}.",
        );
    }

    public function assertNothingRecorded(): void
    {
        PHPUnit::assertEmpty($this->events, "Chronicle recorded events. {$this->summary()}");
    }

    /**
     * Asserts that add() got a field: with only a key, any value; with a value, that exact value
     * (compared with ===); with a callback, that it accepts all fields add() got.
     *
     * @param  string|Closure(array<string, mixed>): bool  $field
     */
    public function assertAdded(string|Closure $field, mixed $value = null): void
    {
        if ($field instanceof Closure) {
            PHPUnit::assertTrue($field($this->addedFields), 'The fields added to Chronicle do not match the callback.');

            return;
        }

        PHPUnit::assertArrayHasKey($field, $this->addedFields, "No [{$field}] field was added to Chronicle.");

        // Two arguments compare the value, so assertAdded('coupon', null) checks for a null.
        if (func_num_args() === 2) {
            PHPUnit::assertSame($value, $this->addedFields[$field], "The [{$field}] field added to Chronicle has another value.");
        }
    }

    public function assertNotAdded(string $field): void
    {
        PHPUnit::assertArrayNotHasKey($field, $this->addedFields, "A [{$field}] field was added to Chronicle.");
    }

    protected function output(Level $level, string $line): void
    {
        $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

        // encodeEvent() asserts that every line is a JSON object.
        if (! is_array($event)) {
            return;
        }

        /** @var array<string, mixed> $event */
        $this->events[] = ['event' => $event, 'level' => $level];
    }

    /**
     * The types of the recorded events, for a failure message.
     */
    private function summary(): string
    {
        if ($this->events === []) {
            return 'It recorded no events.';
        }

        // Every event has a string type: event() sets it from record()'s string argument.
        $types = array_map(
            fn (array $recorded): string => is_string($recorded['event']['type']) ? $recorded['event']['type'] : '?',
            $this->events,
        );

        return 'It recorded: '.implode(', ', $types).'.';
    }
}
