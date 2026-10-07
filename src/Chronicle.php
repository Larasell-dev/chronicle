<?php

namespace Larasell\Chronicle;

use Illuminate\Support\Facades\Log;

class Chronicle
{
    public function record(string $type, array $payload = []): void
    {
        if (! config('chronicle.enabled', true)) {
            return;
        }

        $entry = array_merge([
            'timestamp' => now()->toIso8601String(),
            'type' => $type,
        ], $payload);

        Log::channel(config('chronicle.channel', 'chronicle'))->info(
            (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
        );
    }
}
