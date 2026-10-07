<?php

namespace Larasell\Chronicle;

use Illuminate\Support\Facades\Log;
use RuntimeException;

class Chronicle
{
    public function record(string $type, array $payload = []): void
    {
        if (! config('chronicle.enabled', true)) {
            return;
        }

        $this->assertPostHogConfigured();

        $entry = array_merge([
            'timestamp' => now()->toIso8601String(),
            'type' => $type,
        ], $payload);

        Log::channel(config('chronicle.channel', 'chronicle'))->info(
            (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
        );
    }

    protected function assertPostHogConfigured(): void
    {
        if (! config('chronicle.posthog.enabled', false)) {
            return;
        }

        $apiKey = config('chronicle.posthog.api_key');

        if (is_string($apiKey) && $apiKey !== '') {
            return;
        }

        if (app()->hasDebugModeEnabled()) {
            throw new RuntimeException('POSTHOG_API_KEY is required by chronicle when the posthog channel is enabled, this causes logs to be silently missed. This error stops appearing once POSTHOG_API_KEY is configured');
        }
    }
}
