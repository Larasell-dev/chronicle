<?php

namespace Larasell\Chronicle\Requests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Larasell\Chronicle\Chronicle;
use Larasell\Chronicle\Errors\ErrorPayload;
use Larasell\Chronicle\Requests\Exceptions\InvalidSampler;
use Monolog\Level;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RequestLogger
{
    public function __construct(
        protected Chronicle $chronicle,
    ) {}

    public function log(Request $request, Response $response, float $startTime): void
    {
        $payload = [
            'request' => [
                'id' => substr(bin2hex(random_bytes(16)), 0, 16),
            ],
            'method' => $request->getMethod(),
            'url' => $request->fullUrl(),
            'route' => $request->route()?->getName() ?: $request->route()?->uri(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'ip' => $request->ip(),
            'user' => [
                'id' => $request->user()?->getAuthIdentifier(),
            ],
        ];

        $payload = $this->chronicle->flattenContext($payload);
        $payload = array_merge($payload, $this->chronicle->flattenContext(Context::all()));

        $exception = property_exists($response, 'exception') ? $response->exception : null;

        if ($exception instanceof Throwable) {
            $payload = array_merge($payload, $this->chronicle->flattenContext(ErrorPayload::for($exception)));
        }

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
            throw InvalidSampler::doesNotImplementSampler($sampler);
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
