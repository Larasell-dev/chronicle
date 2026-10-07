<?php

namespace Larasell\Chronicle;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequestLogger
{
    public function __construct(
        protected Chronicle $chronicle,
    ) {}

    public function log(Request $request, Response $response, float $startTime): void
    {
        $this->chronicle->record('request', [
            'request_id' => substr(bin2hex(random_bytes(16)), 0, 16),
            'method' => $request->getMethod(),
            'url' => $request->fullUrl(),
            'route' => $request->route()?->getName() ?: $request->route()?->uri(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'ip' => $request->ip(),
            'user.id' => $request->user()?->getAuthIdentifier(),
        ]);
    }
}
