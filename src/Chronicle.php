<?php

namespace Larasell\Chronicle;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
use Throwable;

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

    public function recordError(Throwable $e, Level|string $level = Level::Error, array $context = []): void
    {
        $payload = array_merge(Context::all(), $this->errorPayload($e, $context));

        $this->record('error', $this->flattenContext($payload), $level);
    }

    /**
     * Collapse nested context arrays into flat, dot-notation string keys.
     * Lists are collapsed into a truncated JSON string.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function flattenContext(array $context, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($context as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (! is_array($value)) {
                $flattened[$path] = $value;
            } elseif (array_is_list($value)) {
                $flattened[$path] = substr((string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 1000);
            } else {
                $flattened = array_merge($flattened, $this->flattenContext($value, $path));
            }
        }

        return $flattened;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function errorPayload(Throwable $e, array $context = []): array
    {
        $structured = $e instanceof StructuredError;

        return array_merge($this->exceptionContext($e), $context, [
            'error' => [
                'code' => $structured ? $e->errorCode() : null,
                'message' => $e->getMessage(),
                'why' => $structured ? $e->errorWhy() : null,
                'fix' => $structured ? $e->errorFix() : null,
                'link' => $structured ? $e->errorLink() : null,
            ],
        ]);
    }

    protected function exceptionContext(Throwable $e): array
    {
        if (! method_exists($e, 'context')) {
            return [];
        }

        $context = $e->context();

        if (! is_array($context)) {
            return [];
        }

        return array_map(
            fn ($value) => is_string($value) && strlen($value) > 1000 ? substr($value, 0, 1000) : $value,
            $context,
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
            throw ChronicleException::make(
                code: 'chronicle.posthog.missing-api-key',
                message: 'POSTHOG_API_KEY is required by chronicle when the posthog channel is enabled, this causes logs to be silently missed. This error stops appearing once POSTHOG_API_KEY is configured',
                why: 'The posthog channel is enabled but no API key is configured, so events cannot be delivered',
                fix: 'Set POSTHOG_API_KEY in your environment or disable the channel via CHRONICLE_POSTHOG_ENABLED=false',
                link: 'https://github.com/larasell-dev/chronicle#posthog',
            );
        }
    }
}
