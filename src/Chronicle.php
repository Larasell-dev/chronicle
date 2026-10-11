<?php

namespace Larasell\Chronicle;

use Illuminate\Support\Facades\Log;
use Monolog\Level;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
        $this->record('error', $this->errorPayload($e, $context), $level);
    }

    public function errorPayload(Throwable $e, array $context = []): array
    {
        $structured = $e instanceof StructuredError;

        return array_merge($this->exceptionContext($e), $context, [
            'error.code' => $structured ? $e->errorCode() : null,
            'error.message' => $e->getMessage(),
            'error.why' => $structured ? $e->errorWhy() : null,
            'error.fix' => $structured ? $e->errorFix() : null,
            'error.link' => $structured ? $e->errorLink() : null,
            'error.status' => $this->errorStatus($e),
        ]);
    }

    protected function errorStatus(Throwable $e): ?int
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        if (method_exists($e, 'getResponse')) {
            $response = $e->getResponse();

            if ($response instanceof Response) {
                return $response->getStatusCode();
            }
        }

        return null;
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
