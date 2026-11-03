<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents\Outputs\Exceptions;

use Larasell\Chronicle\Exceptions\HasStructuredException;
use Larasell\Chronicle\Exceptions\StructuredException;
use LogicException;

/**
 * @internal
 */
final class InvalidChronicleEvent extends LogicException implements StructuredException
{
    use HasStructuredException;

    public static function forPostHogChannel(string $reason): self
    {
        return self::structured(
            errorCode: 'chronicle.posthog.invalid-event',
            message: 'The posthog channel received a log message that is not a JSON object: '.$reason,
            why: 'The posthog driver expects the JSON events Chronicle writes, so it cannot read this message into attributes',
            fix: 'Write to the posthog channel only through Chronicle, or point other log calls at another channel',
            link: 'https://github.com/larasell-dev/chronicle#posthog',
        );
    }
}
