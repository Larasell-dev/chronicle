<?php

namespace Larasell\Chronicle;

use Illuminate\Support\Facades\Log;
use Monolog\Level;
use RuntimeException;

class Chronicle
{
    public function record(string $type, array $payload = [], Level|string $level = Level::Info): void
    {
        if (! config('chronicle.enabled', true)) {
            return;
        }

        $this->assertPostHogConfigured();

        $entry = array_merge([
            'timestamp' => now()->toIso8601String(),
            'type' => $type,
        ], $payload);

        $level = $level instanceof Level ? $level : Level::fromName(ucfirst($level));

        Log::channel(config('chronicle.channel', 'chronicle'))->{$level->getName()}(
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
