<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Larasell\Chronicle\WideEvents\Enums\Level;
use Psr\Log\LoggerInterface;
use Throwable;

class Chronicle
{
    /**
     * The most bytes a string value, or an array encoded as JSON, keeps. Counted in bytes because
     * the cost is in bytes: log lines on disk and the PostHog payload. Strings are cut on UTF-8
     * character boundaries, so a value can end up to three bytes shorter.
     */
    private const VALUE_BYTES_MAX = 1000;

    /** The most bytes a dotted key keeps, so keys can't make an event large either. */
    private const KEY_BYTES_MAX = 200;

    /**
     * The most levels flatten() walks into nested arrays. Deeper arrays are stored as one JSON
     * value, so the work and memory per event stay bounded no matter what an app puts in Context.
     */
    private const DEPTH_MAX = 32;

    /**
     * The most fields an event has, chronicle.fields_dropped included. A wide event rarely needs
     * more than a few dozen fields, and together with the byte limits above this bounds an event
     * at roughly FIELDS_MAX × (KEY_BYTES_MAX + VALUE_BYTES_MAX) before JSON escaping, about 300 KB.
     */
    private const FIELDS_MAX = 256;

    /**
     * Records a wide event of the given type. The fields you pass win over fields in Context.
     *
     * @param  array<mixed>  $fields
     */
    public function record(string $type, array $fields = [], Level|string $level = Level::Info): void
    {
        $this->write($this->event($type, $fields), Level::resolve($level));
    }

    /**
     * Records an error event for the exception. Takes its arguments in the same order as
     * record(), so fields come second and the level last in both.
     *
     * @param  array<mixed>  $fields
     */
    public function recordError(Throwable $exception, array $fields = [], Level|string $level = Level::Error): void
    {
        $this->record('error', ErrorFields::for($exception, $fields), $level);
    }

    /**
     * Builds a wide event, so every event follows the same rules. Fields are flattened into dotted
     * keys and merged in this order, later ones winning: the defaults, everything in Context, the
     * fields. Defaults are fields Chronicle measured itself, such as a request's user.id, which
     * apps may replace through Context.
     *
     * Every event is bounded: see the limits at the top of this class. An event over FIELDS_MAX
     * drops fields, those of Context first, and counts them in chronicle.fields_dropped.
     *
     * @internal
     *
     * @param  array<mixed>  $fields
     * @param  array<mixed>  $defaults
     * @return array<string, mixed>
     */
    public function event(string $type, array $fields, array $defaults = []): array
    {
        $header = [
            'timestamp' => now()->toIso8601String(),
            'type' => mb_strcut($type, 0, self::VALUE_BYTES_MAX, 'UTF-8'),
        ];
        $defaults = $this->flatten($defaults);
        $context = $this->flatten(Context::all());
        $fields = $this->flatten($fields);

        $event = array_merge($header, $defaults, $context, $fields);

        if (count($event) > self::FIELDS_MAX) {
            return $this->eventWithoutOverflow($event, array_diff_key($context, $fields));
        }

        return $event;
    }

    /**
     * Drops fields until the event, with the chronicle.fields_dropped count added, has FIELDS_MAX
     * fields. Fields that only Context sets go first, latest first: they accumulate over a whole
     * request, so they are what grows past the limit, while the defaults and the caller's fields
     * describe this event. The timestamp and type are never dropped.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $contextOnly
     * @return array<string, mixed>
     */
    private function eventWithoutOverflow(array $event, array $contextOnly): array
    {
        $header = ['timestamp' => true, 'type' => true];
        $rest = array_diff_key($event, $header, $contextOnly);

        $dropOrder = array_merge(array_reverse(array_keys($contextOnly)), array_reverse(array_keys($rest)));
        // One more than the overflow, for chronicle.fields_dropped itself.
        $dropped = array_slice($dropOrder, 0, count($event) - self::FIELDS_MAX + 1);

        $event = array_diff_key($event, array_flip($dropped));
        $event['chronicle.fields_dropped'] = count($dropped);

        return $event;
    }

    /**
     * Writes a wide event built by event() to the Chronicle log channel.
     *
     * @internal
     *
     * @param  array<string, mixed>  $event
     */
    public function write(array $event, Level $level): void
    {
        if (! config('chronicle.enabled', true)) {
            return;
        }

        $this->channel()->log(
            $level->value,
            (string) json_encode($event, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }

    /**
     * Returns the logger of the default Chronicle channel. Chronicle's channels live in
     * config/chronicle.php, so they are registered with Laravel's log manager under a chronicle-
     * prefix, which keeps them apart from the app's own channels. A stack's channel names point to
     * Chronicle's channels when one has that name, and to the app's channels otherwise.
     */
    protected function channel(): LoggerInterface
    {
        $channels = Config::array('chronicle.channels', []);

        foreach (array_keys($channels) as $name) {
            $channel = Arr::array($channels, $name);

            if (($channel['driver'] ?? null) === 'stack') {
                $members = Arr::array($channel, 'channels', []);

                $channel['channels'] = array_map(
                    function (int|string $index) use ($members, $channels): string {
                        $member = Arr::string($members, $index);

                        return isset($channels[$member]) ? 'chronicle-'.$member : $member;
                    },
                    array_keys($members),
                );
            }

            config()->set('logging.channels.chronicle-'.$name, $channel);
        }

        return Log::channel('chronicle-'.Config::string('chronicle.default', 'file'));
    }

    /**
     * Flattens nested arrays into dotted keys, in the order they appear, a later key winning over
     * an earlier one with the same name. Lists, and arrays below DEPTH_MAX, are stored as one JSON
     * value, since their positions make poor keys. Strings are cut to VALUE_BYTES_MAX.
     *
     * Walks with an explicit stack rather than recursion, so nesting depth costs no call stack.
     * Children are pushed in reverse, so they pop, and are flattened, in their original order.
     *
     * @param  array<mixed>  $context
     * @return array<string, mixed>
     */
    protected function flatten(array $context): array
    {
        $flattened = [];
        $stack = [];

        foreach (array_reverse($context, true) as $key => $value) {
            $stack[] = [(string) $key, $value, 1];
        }

        while ($stack !== []) {
            [$path, $value, $depth] = array_pop($stack);
            $key = mb_strcut($path, 0, self::KEY_BYTES_MAX, 'UTF-8');

            if (is_string($value)) {
                $flattened[$key] = mb_strcut($value, 0, self::VALUE_BYTES_MAX, 'UTF-8');

                continue;
            }

            if (! is_array($value)) {
                $flattened[$key] = $value;

                continue;
            }

            if ($this->encodesWhole($value, $depth)) {
                [$json, $keptCount] = $this->encodeArray($value);
                $flattened[$key] = $json;

                // The JSON only holds the elements that fit, so the full count says how many are missing.
                if ($keptCount < count($value)) {
                    $flattened[mb_strcut($path.'_count', 0, self::KEY_BYTES_MAX, 'UTF-8')] = count($value);
                }

                continue;
            }

            foreach (array_reverse($value, true) as $childKey => $child) {
                $stack[] = [$path.'.'.$childKey, $child, $depth + 1];
            }
        }

        return $flattened;
    }

    /**
     * Whether flatten() stores an array as one JSON value instead of a field per key: lists,
     * since their keys say nothing, and anything at DEPTH_MAX.
     *
     * @param  array<mixed>  $array
     */
    private function encodesWhole(array $array, int $depth): bool
    {
        if (array_is_list($array)) {
            return true;
        }

        return $depth === self::DEPTH_MAX;
    }

    /**
     * Encodes an array as JSON of at most VALUE_BYTES_MAX bytes. Elements that don't fit are left
     * out whole, never cut, so the value stays valid JSON.
     *
     * @param  array<mixed>  $array
     * @return array{string, int} The JSON, and how many elements it holds.
     */
    private function encodeArray(array $array): array
    {
        $isList = array_is_list($array);
        $parts = [];
        $jsonBytes = 2; // The brackets around the parts.

        foreach ($array as $key => $element) {
            $keyPrefix = $isList ? '' : json_encode((string) $key, JSON_INVALID_UTF8_SUBSTITUTE).':';
            // An element json_encode can't encode, e.g. INF or one nested deeper than DEPTH_MAX, is null.
            $part = $keyPrefix.(json_encode($element, JSON_INVALID_UTF8_SUBSTITUTE, self::DEPTH_MAX) ?: 'null');

            $jsonBytesNext = $jsonBytes + strlen($part) + ($parts === [] ? 0 : 1);

            if ($jsonBytesNext > self::VALUE_BYTES_MAX) {
                break;
            }

            $parts[] = $part;
            $jsonBytes = $jsonBytesNext;
        }

        $json = $isList ? '['.implode(',', $parts).']' : '{'.implode(',', $parts).'}';

        return [$json, count($parts)];
    }
}
