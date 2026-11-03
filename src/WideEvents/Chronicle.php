<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use JsonException;
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
    public const VALUE_BYTES_MAX = 1000;

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
     * @internal
     *
     * Every event is bounded: see the limits at the top of this class. An event over FIELDS_MAX
     * drops fields, those of Context first, and counts them in chronicle.fields_dropped.
     *
     * Every value json_encode can't encode, such as NAN, INF or a resource, becomes null, and
     * chronicle.unencodable maps its key to why, so one bad value never costs the whole event.
     * That map is one JSON value like any other, so it obeys VALUE_BYTES_MAX, adding
     * chronicle.unencodable_count when it is cut. Both count towards FIELDS_MAX.
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
        $unencodable = array_filter($event, fn (mixed $value): bool => $value instanceof UnencodableValue);

        // Room for chronicle.unencodable, and for its _count when the reasons don't all fit.
        $fieldsReserved = count($this->unencodableFields($unencodable));

        if (count($event) + $fieldsReserved > self::FIELDS_MAX) {
            $event = $this->eventWithoutOverflow(
                $event,
                array_diff_key($context, $fields),
                $fieldsReserved,
            );
            $unencodable = array_intersect_key($unencodable, $event);
        }

        foreach ($unencodable as $key => $value) {
            $event[$key] = $value->replacement;
        }

        return array_merge($event, $this->unencodableFields($unencodable));
    }

    /**
     * The fields that report an event's unencodable values: chronicle.unencodable, a JSON object
     * from each key to its reason, and chronicle.unencodable_count when that object had to be cut.
     *
     * @param  array<string, UnencodableValue>  $unencodable
     * @return array<string, int|string>
     */
    private function unencodableFields(array $unencodable): array
    {
        return $this->reasonFields(
            'chronicle.unencodable',
            array_map(fn (UnencodableValue $value): string => $value->reason, $unencodable),
        );
    }

    /**
     * A field that maps keys to reasons as one JSON object, bounded like any value, and its
     * _count when the object had to be cut. No fields when there are no reasons.
     *
     * @param  array<string, string>  $reasons
     * @return array<string, int|string>
     */
    private function reasonFields(string $field, array $reasons): array
    {
        if ($reasons === []) {
            return [];
        }

        [$json, $keptCount] = $this->encodeArray($reasons);

        if ($keptCount < count($reasons)) {
            return [$field => $json, $field.'_count' => count($reasons)];
        }

        return [$field => $json];
    }

    /**
     * Drops fields until the event, with the chronicle.fields_dropped count added, has FIELDS_MAX
     * fields. Fields that only Context sets go first, latest first: they accumulate over a whole
     * request, so they are what grows past the limit, while the defaults and the caller's fields
     * describe this event. The timestamp and type are never dropped. $fieldsReserved leaves room
     * for fields the caller adds afterwards.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $contextOnly
     * @return array<string, mixed>
     */
    private function eventWithoutOverflow(array $event, array $contextOnly, int $fieldsReserved): array
    {
        $header = ['timestamp' => true, 'type' => true];
        $rest = array_diff_key($event, $header, $contextOnly);

        $dropOrder = array_merge(array_reverse(array_keys($contextOnly)), array_reverse(array_keys($rest)));
        // One more than the overflow, for chronicle.fields_dropped itself.
        $dropped = array_slice($dropOrder, 0, count($event) + $fieldsReserved - self::FIELDS_MAX + 1);

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

        $this->channel()->log($level->value, $this->encodeEvent($event));
    }

    /**
     * Encodes an event as one JSON line. event() already replaced every value json_encode can't
     * encode by null, so this can only fail through a bug in Chronicle itself, or an object whose
     * jsonSerialize() fails only the second time. Then it encodes a small event that keeps the type and timestamp and says
     * what failed, so the failure is logged rather than lost.
     *
     * Either way the result is one JSON object on one line. The file channel writes it with
     * inline line breaks allowed, and readers take a line as an event, so a line break inside
     * would split it. PostHogHandler::entry() checks the other side, that a message is an object.
     *
     * @param  array<string, mixed>  $event
     */
    private function encodeEvent(array $event): string
    {
        return $this->encodeLine($event);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function encodeLine(array $event): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

        try {
            return json_encode($event, $flags);
        } catch (Throwable $throwable) {
            return json_encode([
                'timestamp' => is_string($event['timestamp'] ?? null) ? $event['timestamp'] : now()->toIso8601String(),
                'type' => is_string($event['type'] ?? null) ? $event['type'] : 'unknown',
                'chronicle.encode_error' => mb_strcut(
                    $throwable::class.': '.$throwable->getMessage(), 0, self::VALUE_BYTES_MAX, 'UTF-8',
                ),
            ], $flags);
        }
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
     * value, since their positions make poor keys. Strings are cut to VALUE_BYTES_MAX. A value
     * json_encode can't encode is stored as an UnencodableValue, for event() to report.
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
                [, $reason] = $this->encodeElement($value);
                $flattened[$key] = $reason === null ? $value : new UnencodableValue($reason, null);

                continue;
            }

            if ($this->encodesWhole($value, $depth)) {
                [$json, $keptCount, $reason] = $this->encodeArray($value);
                $flattened[$key] = $reason === null ? $json : new UnencodableValue($reason, $json);

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
     * out whole, never cut, so the value stays valid JSON. An element that can't be encoded, such
     * as INF or one nested deeper than DEPTH_MAX, becomes null, and the reason names the first one.
     *
     * @param  array<mixed>  $array
     * @return array{string, int, ?string} The JSON, how many elements it holds, and why some of
     *                                     them couldn't be encoded, or null when all could.
     */
    private function encodeArray(array $array): array
    {
        $isList = array_is_list($array);
        $parts = [];
        $jsonBytes = 2; // The brackets around the parts.
        $failures = [];

        foreach ($array as $key => $element) {
            [$part, $reason] = $this->encodeElement($element);
            $keyPrefix = $isList ? '' : json_encode((string) $key, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR).':';
            $part = $keyPrefix.$part;

            $jsonBytesNext = $jsonBytes + strlen($part) + ($parts === [] ? 0 : 1);

            if ($jsonBytesNext > self::VALUE_BYTES_MAX) {
                break;
            }

            $parts[] = $part;
            $jsonBytes = $jsonBytesNext;

            // Only elements that made it into the JSON count, since only they are nulls in it.
            if ($reason !== null) {
                $failures[] = "[{$key}] {$reason}";
            }
        }

        $json = $isList ? '['.implode(',', $parts).']' : '{'.implode(',', $parts).'}';

        if ($failures === []) {
            return [$json, count($parts), null];
        }

        $more = count($failures) - 1;

        return [$json, count($parts), $failures[0].($more === 0 ? '' : ", and {$more} more")];
    }

    /**
     * Encodes one value as JSON. A value json_encode can't encode becomes null, with the reason:
     * NAN, INF and -INF by name, anything else by its type and json_encode's error. An exception
     * from jsonSerialize() is caught too, so one bad object never costs the whole event.
     *
     * @return array{string, ?string} The JSON, and why the value couldn't be encoded, or null.
     */
    private function encodeElement(mixed $element): array
    {
        try {
            return [$this->encodeJson($element), null];
        } catch (JsonException $exception) {
            if (is_float($element)) {
                if (is_nan($element)) {
                    return ['null', 'NAN'];
                }

                return ['null', $element > 0 ? 'INF' : '-INF'];
            }

            return ['null', get_debug_type($element).': '.$exception->getMessage()];
        } catch (Throwable $throwable) {
            return ['null', get_debug_type($element).': jsonSerialize() threw '.$throwable::class];
        }
    }

    /**
     * json_encode, throwing on failure. It also runs the jsonSerialize() of every object it meets
     * and lets their exceptions through unchanged, which PHPStan's signature of json_encode leaves
     * out, so this declares them.
     *
     * @throws JsonException When the value can't be encoded.
     * @throws Throwable When a jsonSerialize() throws.
     */
    private function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR, self::DEPTH_MAX);
    }
}
