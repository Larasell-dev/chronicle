<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents\Enums;

use Larasell\Chronicle\Exceptions\StructuredInvalidArgumentException;
use Monolog\Level as MonologLevel;

/**
 * The level of a wide event. Chronicle's own enum, so apps don't depend on Monolog to pick one.
 * Each case's value is the PSR-3 name, so the string 'warning' and Level::Warning mean the same.
 */
enum Level: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';
    case Alert = 'alert';
    case Emergency = 'emergency';

    /**
     * The level a caller passed, as a case or its value. Case is ignored, so 'Warning' works too.
     * An unknown name is a programming error, so it throws rather than picking a level.
     */
    public static function resolve(self|string $level): self
    {
        if ($level instanceof self) {
            return $level;
        }

        return self::tryFrom(strtolower($level)) ?? throw new StructuredInvalidArgumentException(
            errorCode: 'chronicle.level.unknown',
            message: "There is no log level named [{$level}]",
            why: 'Chronicle only knows the PSR-3 levels',
            fix: 'Pass a '.self::class.' case, or one of: '.implode(', ', array_column(self::cases(), 'value')),
        );
    }

    /**
     * @internal
     */
    public function toMonolog(): MonologLevel
    {
        return match ($this) {
            self::Debug => MonologLevel::Debug,
            self::Info => MonologLevel::Info,
            self::Notice => MonologLevel::Notice,
            self::Warning => MonologLevel::Warning,
            self::Error => MonologLevel::Error,
            self::Critical => MonologLevel::Critical,
            self::Alert => MonologLevel::Alert,
            self::Emergency => MonologLevel::Emergency,
        };
    }
}
