<?php

namespace Larasell\Chronicle\Outputs\Exceptions;

use Larasell\Chronicle\Errors\HasStructuredError;
use Larasell\Chronicle\Errors\StructuredError;
use LogicException;

final class MissingPostHogApiKey extends LogicException implements StructuredError
{
    use HasStructuredError;

    public static function forPostHogChannel(): self
    {
        return self::structured(
            code: 'chronicle.posthog.missing-api-key',
            message: 'POSTHOG_API_KEY is required by chronicle when the posthog channel is enabled, this causes logs to be silently missed. This error stops appearing once POSTHOG_API_KEY is configured',
            why: 'The posthog channel is enabled but no API key is configured, so events cannot be delivered',
            fix: 'Set POSTHOG_API_KEY in your environment or disable the channel via CHRONICLE_POSTHOG_ENABLED=false',
            link: 'https://github.com/larasell-dev/chronicle#posthog',
        );
    }
}
