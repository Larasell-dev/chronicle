<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Facades;

use Illuminate\Support\Facades\Facade;
use Larasell\Chronicle\Exceptions\StructuredLogicException;
use Larasell\Chronicle\Testing\ChronicleFake;
use Larasell\Chronicle\WideEvents\Chronicle as WideEventsChronicle;
use Larasell\Chronicle\WideEvents\Requests\RequestLogger;

/**
 * @method static void record(string $type, array<mixed> $fields = [], \Larasell\Chronicle\WideEvents\Enums\Level|string $level = \Larasell\Chronicle\WideEvents\Enums\Level::Info)
 * @method static void recordError(\Throwable $exception, array<mixed> $fields = [], \Larasell\Chronicle\WideEvents\Enums\Level|string $level = \Larasell\Chronicle\WideEvents\Enums\Level::Error)
 * @method static void add(string $key, mixed $value)
 * @method static mixed scope(string $type, \Closure $callback)
 * @method static list<array<string, mixed>> recorded(?string $type = null, ?\Closure $callback = null)
 * @method static array<string, mixed> added()
 * @method static void assertRecorded(string $type, ?\Closure $callback = null)
 * @method static void assertRecordedTimes(string $type, int $times)
 * @method static void assertNotRecorded(string $type, ?\Closure $callback = null)
 * @method static void assertNothingRecorded()
 * @method static void assertAdded(string|\Closure $field, mixed $value = null)
 * @method static void assertNotAdded(string $field)
 *
 * @see WideEventsChronicle
 * @see ChronicleFake The assertions, available after fake().
 */
class Chronicle extends Facade
{
    /**
     * Puts a ChronicleFake in place of Chronicle, so the test writes no events and can assert on
     * them. Calling it again keeps the same fake, and the events it has.
     */
    public static function fake(): ChronicleFake
    {
        $chronicle = static::getFacadeRoot();

        if ($chronicle instanceof ChronicleFake) {
            return $chronicle;
        }

        if (! $chronicle instanceof WideEventsChronicle) {
            throw new StructuredLogicException(
                errorCode: 'chronicle.fake.no-chronicle',
                message: 'The container resolved '.get_debug_type($chronicle).' for '.WideEventsChronicle::class,
                why: 'Chronicle::fake() replaces the Chronicle that ChronicleServiceProvider registers',
                fix: 'Register Larasell\Chronicle\ChronicleServiceProvider in the app under test',
            );
        }

        $fake = new ChronicleFake($chronicle);
        static::swap($fake);

        // Built at boot with the real Chronicle, so request events would bypass the fake.
        app()->forgetInstance(RequestLogger::class);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return WideEventsChronicle::class;
    }
}
