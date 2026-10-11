<?php

namespace Larasell\Chronicle;

use Illuminate\Http\Request;
use Illuminate\Http\Response as LaravelResponse;
use Illuminate\Support\Facades\Context;
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

        $payload = array_merge($payload, $this->chronicle->flattenContext(Context::all()));

        if ($response instanceof LaravelResponse && $response->exception !== null) {
            $payload = array_merge($payload, $this->chronicle->errorPayload($response->exception));
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
            throw ChronicleException::make(
                code: 'chronicle.sampling.invalid-sampler',
                message: 'chronicle.sampling must resolve to an instance of '.Sampler::class,
                why: 'chronicle.sampling resolved to '.$sampler::class,
                fix: 'Implement the '.Sampler::class.' contract or remove the chronicle.sampling config',
            );
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
