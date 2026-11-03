<?php

namespace Larasell\Chronicle;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Larasell\Chronicle\WideEvents\ErrorFields;
use Monolog\Level;
use Psr\Log\LoggerInterface;
use Throwable;

class Chronicle
{
    /**
     * Records a wide event of the given type. The fields you pass win over fields in Context.
     */
    public function record(string $type, array $fields = [], Level|string $level = Level::Info): void
    {
        $this->write($this->event($type, $fields), $level);
    }

    public function recordError(Throwable $e, Level|string $level = Level::Error, array $context = []): void
    {
        $this->record('error', ErrorFields::for($e, $context), $level);
    }

    /**
     * Builds a wide event, so every event follows the same rules. Fields are flattened into dotted
     * keys and merged in this order, later ones winning: the defaults, everything in Context, the
     * fields. Defaults are fields Chronicle measured itself, such as a request's user.id, which
     * apps may replace through Context.
     *
     * @internal
     *
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
     */
    public function write(array $event, Level|string $level): void
    {
        if (! config('chronicle.enabled', true)) {
            return;
        }

        $level = $level instanceof Level ? $level : Level::fromName(ucfirst($level));

        $this->channel()->{$level->getName()}(
            (string) json_encode($event, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
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
        $channels = config('chronicle.channels', []);

        foreach ($channels as $name => $channel) {
            if (($channel['driver'] ?? null) === 'stack') {
                $channel['channels'] = array_map(
                    fn (string $name): string => isset($channels[$name]) ? 'chronicle-'.$name : $name,
                    $channel['channels'] ?? [],
                );
            }

            config()->set('logging.channels.chronicle-'.$name, $channel);
        }

        return Log::channel('chronicle-'.config('chronicle.default', 'file'));
    }

    /**
     * Collapse nested context arrays into flat, dot-notation string keys.
     * Lists are collapsed into a truncated JSON string.
     *
     * @param  array<string, mixed>  $context
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
