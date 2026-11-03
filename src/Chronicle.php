<?php

declare(strict_types=1);

namespace Larasell\Chronicle;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Larasell\Chronicle\WideEvents\ErrorFields;
use Monolog\Level;
use Psr\Log\LoggerInterface;
use Throwable;

class Chronicle
{
    /**
     * Records a wide event of the given type. The fields you pass win over fields in Context.
     *
     * @param  array<mixed>  $fields
     */
    public function record(string $type, array $fields = [], Level|string $level = Level::Info): void
    {
        $this->write($this->event($type, $fields), $level);
    }

    /**
     * @param  array<mixed>  $context
     */
    public function recordError(Throwable $exception, Level|string $level = Level::Error, array $context = []): void
    {
        $this->record('error', ErrorFields::for($exception, $context), $level);
    }

    /**
     * Builds a wide event, so every event follows the same rules. Fields are flattened into dotted
     * keys and merged in this order, later ones winning: the defaults, everything in Context, the
     * fields. Defaults are fields Chronicle measured itself, such as a request's user.id, which
     * apps may replace through Context.
     *
     * @internal
     *
     * @param  array<mixed>  $fields
     * @param  array<mixed>  $defaults
     * @return array<string, mixed>
     */
    public function event(string $type, array $fields, array $defaults = []): array
    {
        return array_merge(
            ['timestamp' => now()->toIso8601String(), 'type' => $type],
            $this->flatten($defaults),
            $this->flatten(Context::all()),
            $this->flatten($fields),
        );
    }

    /**
     * Writes a wide event built by event() to the Chronicle log channel.
     *
     * @internal
     *
     * @param  array<string, mixed>  $event
     */
    public function write(array $event, Level|string $level): void
    {
        if (! config('chronicle.enabled', true)) {
            return;
        }

        $level = $level instanceof Level ? $level : $this->levelNamed($level);

        $this->channel()->{$level->getName()}(
            (string) json_encode($event, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
        );
    }

    protected function levelNamed(string $name): Level
    {
        $name = strtoupper($name);

        if (! in_array($name, Level::NAMES, true)) {
            throw new InvalidArgumentException("[{$name}] is not a log level.");
        }

        return Level::fromName($name);
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
     * Collapse nested context arrays into flat, dot-notation string keys.
     * Lists are collapsed into a truncated JSON string.
     *
     * @param  array<mixed>  $context
     * @return array<string, mixed>
     */
    protected function flatten(array $context, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($context as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (! is_array($value)) {
                $flattened[$path] = $value;
            } elseif (array_is_list($value)) {
                $flattened[$path] = substr((string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 1000);
            } else {
                $flattened = array_merge($flattened, $this->flatten($value, $path));
            }
        }

        return $flattened;
    }
}
