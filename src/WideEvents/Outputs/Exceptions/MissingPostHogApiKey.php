<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents\Outputs\Exceptions;

use Larasell\Chronicle\Exceptions\HasStructuredException;
use Larasell\Chronicle\Exceptions\StructuredException;
use LogicException;

/**
 * @internal
 */
final class MissingPostHogApiKey extends LogicException implements StructuredException
{
    use HasStructuredException;

    public static function forPostHogChannel(): self
    {
        return self::structured(
            code: 'chronicle.posthog.missing-api-key',
            message: 'POSTHOG_API_KEY is required by chronicle when it writes to the posthog channel, this causes logs to be silently missed. This error stops appearing once POSTHOG_API_KEY is configured',
            why: 'Chronicle writes to the posthog channel but no API key is configured, so events cannot be delivered',
            fix: 'Set POSTHOG_API_KEY in your environment or set CHRONICLE_CHANNEL to a channel without posthog',
            link: 'https://github.com/larasell-dev/chronicle#posthog',
        );
    }
}
