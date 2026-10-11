<?php

namespace Larasell\Chronicle;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Monolog\Level;
use Symfony\Component\HttpFoundation\Response;

class RequestLogger
{
    public function __construct(
        protected Chronicle $chronicle,
    ) {}

    public function log(Request $request, Response $response, float $startTime): void
    {
        $payload = [
            'request_id' => substr(bin2hex(random_bytes(16)), 0, 16),
            'method' => $request->getMethod(),
            'url' => $request->fullUrl(),
            'route' => $request->route()?->getName() ?: $request->route()?->uri(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'ip' => $request->ip(),
            'user.id' => $request->user()?->getAuthIdentifier(),
        ];

        if (! $this->shouldSample($request, $response, $payload)) {
            return;
        }

        $this->chronicle->record('request', $payload, $this->levelForStatus($response->getStatusCode()));
    }

    protected function shouldSample(Request $request, Response $response, array $payload): bool
    {
        $sampler = config('chronicle.sampling');

        if ($sampler === null) {
            return true;
        }

        $sampler = app()->make($sampler);

        if (! $sampler instanceof Sampler) {
            throw new InvalidArgumentException('chronicle.sampling must resolve to an instance of '.Sampler::class);
        }

        return $sampler->shouldLog($request, $response, $payload);
    }

    protected function levelForStatus(int $status): Level
    {
        if ($status >= 500) {
            return Level::Error;
        }

        if ($status >= 400) {
            return Level::Warning;
        }

        return Level::Info;
    }
}
