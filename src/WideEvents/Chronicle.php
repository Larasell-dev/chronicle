<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use JsonException;
use JsonSerializable;
use Larasell\Chronicle\Support\Assert;
use Larasell\Chronicle\WideEvents\Enums\Level;
use Psr\Log\LoggerInterface;
use stdClass;
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
     * Maps the error code of each failed assertion to its message, on an event built outside
     * debug mode. It and its _count are the only fields allowed beyond FIELDS_MAX: they appear only
     * when Chronicle has a bug, and reserving room for them would cost every event a field.
     */
    private const ASSERTION_FAILED = 'chronicle.assertion_failed';

    /** What a value of a key in chronicle.redact is logged as. */
    public const REDACTED = '[redacted]';

    /**
     * The fields add() collected for the request LogRequests is logging. Null while no request is
     * being logged, so add() keeps nothing that no event would ever carry, e.g. in a queue worker.
     *
     * @var array<string, mixed>|null
     */
    private ?array $addedFields = null;

    /**
     * Whether the warning that LogRequests ran twice in one request was logged. Once per process,
     * since a middleware added twice runs twice on every request.
     */
    private bool $nestedRequestWarned = false;

    /**
     * The keys of chronicle.redact as words(), so a key is matched without normalizing them again.
     *
     * @var list<string>
     */
    private readonly array $redactedWords;

    /**
     * @param  list<string>  $redactedKeys  The keys of chronicle.redact.
     * @param  ?Redactor  $redactor  The app's chronicle.redactor, or null for none.
     */
    public function __construct(
        array $redactedKeys = [],
        private readonly ?Redactor $redactor = null,
    ) {
        $this->redactedWords = array_map(self::words(...), $redactedKeys);
    }

    /**
     * Adds a field to the event of the current request, and only to it: unlike Context, the field
     * isn't added to other log lines or passed on to queued jobs. Outside a request that
     * LogRequests logs, there is no such event, so the field is discarded. Adding a key again
     * replaces its value.
     */
    public function add(string $key, mixed $value): void
    {
        if ($this->addedFields === null) {
            return;
        }

        $this->addedFields[$key] = $value;
    }

    /**
     * Starts collecting add()'s fields for the request that LogRequests is about to handle, and
     * returns true. Returns false when LogRequests is already logging this request, because it
     * was added twice, e.g. by the app and by a package: the request is then logged once, by the
     * outer LogRequests, and a warning says so once per process. Logging it again would write two
     * events, and the inner id would replace the one in the X-Request-Id header.
     *
     * @internal
     */
    public function beginRequest(): bool
    {
        if ($this->addedFields === null) {
            $this->addedFields = [];

            return true;
        }

        if (! $this->nestedRequestWarned) {
            $this->nestedRequestWarned = true;

            Log::warning('Chronicle\'s LogRequests middleware runs twice in one request, so it logs the request once and skips the second run. Add LogRequests once, to the global middleware of the app; packages should not add it.', [
                'error.code' => 'chronicle.request.logged-twice',
            ]);
        }

        return false;
    }

    /**
     * Stops collecting add()'s fields, once LogRequests is done with the request, also when
     * handling it threw.
     *
     * @internal
     */
    public function endRequest(): void
    {
        $this->addedFields = null;
    }

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
     * Records the event of one HTTP request, for RequestLogger, with the fields add() collected.
     * $shouldLog sees the finished event, so a sampler decides on exactly what would be written.
     *
     * @internal
     *
     * @param  array<mixed>  $errorFields
     * @param  Closure(array<string, mixed>): bool  $shouldLog  Whether to write the finished event.
     * @param  array<string, string>  $assertionFailures  The caller's failed assertions, error code to message.
     */
    public function recordRequest(
        EventDefaults $requestFields,
        array $errorFields,
        Level $level,
        Closure $shouldLog,
        array $assertionFailures = [],
    ): void {
        $event = $this->event('request', $errorFields, $requestFields, $assertionFailures, $this->addedFields ?? []);

        if ($shouldLog($event) === false) {
            return;
        }

        $this->write($event, $level);
    }

    /**
     * Builds a wide event, so every event follows the same rules. Fields are flattened into dotted
     * keys and merged in this order, later ones winning: everything in Context, the fields add()
     * collected, the defaults, the fields, and last the timestamp and type. The defaults are an
     * EventDefaults, not a second array, so the two can't be swapped. They are fields Chronicle
     * measured itself, such as a request's status, which a package's Context or add() must not
     * change, since every package shares them. Only a default Chronicle couldn't measure, which is
     * null, such as the user.id of a customer signed in through another guard, can be filled in.
     *
     * Every event is bounded: see the limits at the top of this class. An event over FIELDS_MAX
     * drops fields, those of Context and add() first, and counts them in chronicle.fields_dropped.
     *
     * Every value json_encode can't encode, such as NAN, INF or a resource, becomes null, and
     * chronicle.unencodable maps its key to why, so one bad value never costs the whole event.
     * That map is one JSON value like any other, so it obeys VALUE_BYTES_MAX, adding
     * chronicle.unencodable_count when it is cut. Both count towards FIELDS_MAX.
     *
     * An assertion that fails while the event is built, outside debug mode, is recorded in
     * chronicle.assertion_failed, together with the $assertionFailures of the caller.
     *
     * @param  array<mixed>  $fields
     * @param  array<string, string>  $assertionFailures  The caller's failed assertions, error code to message.
     * @param  array<mixed>  $added  The fields add() collected.
     * @return array<string, mixed>
     */
    private function event(
        string $type,
        array $fields,
        ?EventDefaults $defaults = null,
        array $assertionFailures = [],
        array $added = [],
    ): array {
        $header = [
            'timestamp' => now()->toIso8601String(),
            'type' => mb_strcut($type, 0, self::VALUE_BYTES_MAX, 'UTF-8'),
        ];
        $defaults = $this->flatten($defaults->fields ?? []);
        $measured = array_filter($defaults, fn (mixed $value): bool => $value !== null);
        // Added after Context, so a field meant for this event wins over one meant for every line.
        $context = array_merge($this->flatten(Context::all()), $this->flatten($added));
        $fields = $this->flatten($fields);

        // The header and defaults come first too, so they keep their place at the start of the event.
        $event = array_merge($header, $defaults, $context, $measured, $fields, $header);
        $unencodable = array_filter($event, fn (mixed $value): bool => $value instanceof UnencodableValue);

        // Room for chronicle.unencodable, and for its _count when the reasons don't all fit.
        $fieldsReserved = count($this->unencodableFields($unencodable));

        if (count($event) + $fieldsReserved > self::FIELDS_MAX) {
            $event = $this->eventWithoutOverflow(
                $event,
                array_diff_key($context, $header, $defaults, $fields),
                $fieldsReserved,
            );
            $unencodable = array_intersect_key($unencodable, $event);
        }

        foreach ($unencodable as $key => $value) {
            $event[$key] = $value->replacement;
        }

        $event = array_merge($event, $this->unencodableFields($unencodable));

        // The other side of the promises flatten() and eventWithoutOverflow() make, checked on
        // the finished event: no marker, array or object may be left by now.
        $failures = $this->check(
            $assertionFailures,
            count($event) <= self::FIELDS_MAX,
            'chronicle.assert.field-limit',
            'event() built '.count($event).' fields, more than FIELDS_MAX ('.self::FIELDS_MAX.')',
        );
        $unbounded = $this->firstUnboundedField($event);
        $failures = $this->check($failures, $unbounded === null, 'chronicle.assert.value-bounds', "event() built {$unbounded}");

        return array_merge($event, $this->reasonFields(self::ASSERTION_FAILED, $failures));
    }

    /**
     * Asserts a guarantee of the event being built. Outside debug mode, where a failed assertion
     * doesn't throw, the failure is added to $failures, so the event can say it was built wrong.
     *
     * @param  array<string, string>  $failures
     * @return array<string, string>
     */
    private function check(array $failures, bool $condition, string $errorCode, string $message): array
    {
        if (Assert::that($condition, $errorCode, $message)) {
            return $failures;
        }

        $failures[$errorCode] = $message;

        return $failures;
    }

    /**
     * The first field that breaks the bounds of an event, described, or null when every field
     * keeps them: a key of at most KEY_BYTES_MAX bytes, and a value that is null, a bool, an int,
     * a float json_encode can encode, or a string of at most VALUE_BYTES_MAX bytes.
     *
     * @param  array<mixed>  $fields
     */
    private function firstUnboundedField(array $fields): ?string
    {
        foreach ($fields as $key => $value) {
            $keyBytes = strlen((string) $key);

            if ($keyBytes > self::KEY_BYTES_MAX) {
                return "a key of {$keyBytes} bytes, more than KEY_BYTES_MAX (".self::KEY_BYTES_MAX.')';
            }

            $problem = $this->unboundedValue($value);

            if ($problem !== null) {
                return "{$key}: {$problem}";
            }
        }

        return null;
    }

    private function unboundedValue(mixed $value): ?string
    {
        if (is_string($value)) {
            $valueBytes = strlen($value);

            if ($valueBytes > self::VALUE_BYTES_MAX) {
                return "a string of {$valueBytes} bytes, more than VALUE_BYTES_MAX (".self::VALUE_BYTES_MAX.')';
            }

            return null;
        }

        if (is_float($value)) {
            if (is_finite($value)) {
                return null;
            }

            return 'NAN or INF, which json_encode can\'t encode';
        }

        if (is_scalar($value)) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        return 'a '.get_debug_type($value).", which isn't a plain value";
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

        Assert::that(
            $keptCount <= count($reasons),
            'chronicle.assert.array-elements',
            "encodeArray() kept {$keptCount} of ".count($reasons).' reasons',
        );

        if ($keptCount < count($reasons)) {
            return [$field => $json, $field.'_count' => count($reasons)];
        }

        return [$field => $json];
    }

    /**
     * Drops fields until the event, with the chronicle.fields_dropped count added, has FIELDS_MAX
     * fields. Fields that only Context or add() set go first, latest first: they accumulate over a whole
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
     * @param  array<string, mixed>  $event
     */
    private function write(array $event, Level $level): void
    {
        // The other side of event()'s field limit. chronicle.assertion_failed may go beyond it.
        $fieldCount = count(array_diff_key($event, array_flip([self::ASSERTION_FAILED, self::ASSERTION_FAILED.'_count'])));
        Assert::that(
            $fieldCount <= self::FIELDS_MAX,
            'chronicle.assert.field-limit',
            "write() got {$fieldCount} fields, more than FIELDS_MAX (".self::FIELDS_MAX.')',
        );

        if (! config('chronicle.enabled')) {
            return;
        }

        $this->channel()->log($level->value, $this->encodeEvent($event));
    }

    /**
     * Encodes an event as one JSON line. event() already replaced every object by plain data and
     * every value json_encode can't encode by null, so this can only fail through a bug in
     * Chronicle itself. Then it encodes a small event that keeps the type and timestamp and says
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
        $line = $this->encodeLine($event);

        Assert::that(str_starts_with($line, '{'), 'chronicle.assert.event-json-object', "encodeEvent() built a line that isn't a JSON object");
        Assert::that(! str_contains($line, "\n"), 'chronicle.assert.event-one-line', 'encodeEvent() built an event with a line break in it');

        return $line;
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
     * Returns the logger of the default Chronicle channel. ChronicleServiceProvider registers the
     * channels once at boot, and Laravel caches each logger by name, so this only picks one.
     * chronicle.default is read on each event, since it is only a name.
     */
    protected function channel(): LoggerInterface
    {
        return Log::channel('chronicle-'.Config::string('chronicle.default'));
    }

    /**
     * Flattens nested arrays into dotted keys, in the order they appear, a later key winning over
     * an earlier one with the same name. Lists, and arrays below DEPTH_MAX, are stored as one JSON
     * value, since their positions make poor keys. Strings are cut to VALUE_BYTES_MAX. Objects are
     * replaced by their JSON form first, so they are bounded like the strings and arrays they
     * become. A value json_encode can't encode is stored as an UnencodableValue, for event() to
     * report.
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
            $stack[] = [(string) $key, (string) $key, $value, 1];
        }

        while ($stack !== []) {
            [$path, $lastKey, $value, $depth] = array_pop($stack);
            $key = mb_strcut($path, 0, self::KEY_BYTES_MAX, 'UTF-8');
            // Before anything else looks at the value, so a secret is never even measured.
            $value = $this->redacted($path, $lastKey, $value);

            // An object is replaced by its JSON form, which the branches below then bound like
            // any other string or array.
            if (is_object($value)) {
                [$value, $reason] = $this->jsonForm($value);

                if ($reason !== null) {
                    $flattened[$key] = new UnencodableValue($reason, null);

                    continue;
                }
            }

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
                $value = $this->redactedElements($path, $value);
                [$json, $keptCount, $reason] = $this->encodeArray($value);
                $flattened[$key] = $reason === null ? $json : new UnencodableValue($reason, $json);
                Assert::that(
                    $keptCount <= count($value),
                    'chronicle.assert.array-elements',
                    "encodeArray() kept {$keptCount} of ".count($value)." elements of {$key}",
                );

                // The JSON only holds the elements that fit, so the full count says how many are missing.
                if ($keptCount < count($value)) {
                    $flattened[mb_strcut($path.'_count', 0, self::KEY_BYTES_MAX, 'UTF-8')] = count($value);
                }

                continue;
            }

            foreach (array_reverse($value, true) as $childKey => $child) {
                $stack[] = [$path.'.'.$childKey, (string) $childKey, $child, $depth + 1];
            }
        }

        // A marker stands for its replacement, which is what event() writes.
        $unbounded = $this->firstUnboundedField(array_map(
            fn (mixed $value): mixed => $value instanceof UnencodableValue ? $value->replacement : $value,
            $flattened,
        ));
        Assert::that($unbounded === null, 'chronicle.assert.value-bounds', "flatten() built {$unbounded}");

        return $flattened;
    }

    /**
     * The value to log for $key: REDACTED when the key matches chronicle.redact, otherwise what
     * the app's Redactor returns. A dotted key, e.g. stripe.secret from Context, matches when any
     * of its parts does.
     */
    private function redacted(string $path, string $key, mixed $value): mixed
    {
        foreach (explode('.', $key) as $part) {
            $words = self::words($part);

            foreach ($this->redactedWords as $redactedWords) {
                if (str_contains($words, $redactedWords)) {
                    return self::REDACTED;
                }
            }
        }

        if ($this->redactor === null) {
            return $value;
        }

        return $this->redactor->redact($path, $value);
    }

    /**
     * Redacts the elements of an array flatten() stores as one JSON value, at every level
     * json_encode will encode, so a secret in a list of arrays is caught like one in a field.
     * Objects become their JSON form first, so their keys are checked too. Walks with an explicit
     * stack, like flatten(), so nesting depth costs no call stack.
     *
     * @param  array<mixed>  $array
     * @return array<mixed>
     */
    private function redactedElements(string $path, array $array): array
    {
        // Each array gets an id, its parent's id and its key there. Children get higher ids than
        // their parent, so going through the ids backwards puts each finished array into its
        // parent. The parent holds a null at the child's key until then, so keys keep their order.
        $results = [];
        $parents = [];
        $keys = [];
        $stack = [[0, $path, $array, 1]];
        $idNext = 1;

        while ($stack !== []) {
            [$id, $nodePath, $node, $depth] = array_pop($stack);
            $result = [];
            $children = [];

            foreach ($node as $childKey => $child) {
                $childPath = $nodePath.'.'.$childKey;
                $child = $this->redacted($childPath, (string) $childKey, $child);

                if (is_object($child)) {
                    [$form, $reason] = $this->jsonForm($child);
                    // An object without a JSON form stays, for encodeElement() to report.
                    $child = $reason === null ? $form : $child;
                }

                if (is_array($child) && $depth < self::DEPTH_MAX) {
                    $result[$childKey] = null;
                    $parents[$idNext] = $id;
                    $keys[$idNext] = $childKey;
                    $children[] = [$idNext, $childPath, $child, $depth + 1];
                    $idNext++;

                    continue;
                }

                $result[$childKey] = $child;
            }

            $results[$id] = $result;
            // Reversed, so they pop, and are redacted, in their original order.
            array_push($stack, ...array_reverse($children));
        }

        for ($id = $idNext - 1; $id > 0; $id--) {
            $results[$parents[$id]][$keys[$id]] = $results[$id];
            unset($results[$id]);
        }

        return $results[0];
    }

    /**
     * A key as lowercase words, each between underscores, e.g. _x_csrf_token_ for X-Csrf-Token
     * and _api_token_ for apiToken, so a redacted key matches whole words in any spelling.
     */
    private static function words(string $key): string
    {
        $snake = strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key));

        return '_'.trim((string) preg_replace('/[^a-z0-9]+/', '_', $snake), '_').'_';
    }

    /**
     * An object as plain data: what jsonSerialize() or toArray() returns, or the properties of a
     * stdClass. Any other object, or one of those methods returning an object, goes through
     * json_encode and back, so flatten() never stores an object and every value obeys the limits.
     *
     * @return array{mixed, ?string} The object as plain data, or null and why it has none.
     */
    private function jsonForm(object $object): array
    {
        try {
            $form = match (true) {
                $object instanceof JsonSerializable => $object->jsonSerialize(),
                $object instanceof Arrayable => $object->toArray(),
                default => $object,
            };
        } catch (Throwable $throwable) {
            $method = $object instanceof JsonSerializable ? 'jsonSerialize()' : 'toArray()';

            return [null, get_debug_type($object).": {$method} threw ".$throwable::class];
        }

        if ($form instanceof stdClass) {
            return [get_object_vars($form), null];
        }

        if (! is_object($form)) {
            return [$form, null];
        }

        [$json, $reason] = $this->encodeElement($form);

        if ($reason !== null) {
            return [null, $reason];
        }

        return [json_decode($json, true, flags: JSON_THROW_ON_ERROR), null];
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

        Assert::that(
            strlen($json) <= self::VALUE_BYTES_MAX,
            'chronicle.assert.value-bounds',
            'encodeArray() built '.strlen($json).' bytes of JSON, more than VALUE_BYTES_MAX ('.self::VALUE_BYTES_MAX.')',
        );
        Assert::that(
            count($parts) <= count($array),
            'chronicle.assert.array-elements',
            'encodeArray() kept '.count($parts).' of '.count($array).' elements',
        );

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
