<?php

namespace Larasell\Chronicle\WideEvents\Requests;

use Illuminate\Http\Request;
use Larasell\Chronicle\Chronicle;
use Larasell\Chronicle\WideEvents\ErrorFields;
use Larasell\Chronicle\WideEvents\Requests\Exceptions\InvalidSampler;
use Monolog\Level;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @internal
 */
class RequestLogger
{
    public function __construct(
        protected Chronicle $chronicle,
    ) {}

    public function log(Request $request, Response $response, float $startTime): void
    {
        $payload = [
            'request' => [
                // LogRequests' id in Context replaces this one; it only shows when the logger is called directly.
                'id' => substr(bin2hex(random_bytes(16)), 0, 16),
            ],
            'method' => $request->getMethod(),
            'url' => $request->fullUrl(),
            'route' => $this->routeName($request),
            'status' => $response->getStatusCode(),
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'ip' => $request->ip(),
            'user' => [
                'id' => $request->user()?->getAuthIdentifier(),
            ],
        ];

        $exception = property_exists($response, 'exception') ? $response->exception : null;
        $errorFields = $exception instanceof Throwable ? ErrorFields::for($exception) : [];

        $event = $this->chronicle->event('request', $errorFields, defaults: $payload);

        if ($this->shouldSample($request, $response, $event)) {
            $this->chronicle->write($event, $this->levelForStatus($response->getStatusCode()));
        }
    }

    /**
     * The route's name, or its uri when it has none. A route without a name in a group with a name
     * prefix, e.g. Route::name('shop.'), gets the prefix as its name, which names nothing.
     */
    protected function routeName(Request $request): ?string
    {
        $route = $request->route();
        $name = $route?->getName();

        if ($name === null) {
            return $route?->uri();
        }

        if ($name === '') {
            return $route?->uri();
        }

        if (str_ends_with($name, '.')) {
            return $route?->uri();
        }

        return $name;
    }

    /**
     * Asks the configured sampler whether to keep the event. It runs once the event is complete,
     * so a sampler can decide on the outcome, such as keeping every failure and slow request and
     * only a share of the rest. Without a sampler every event is kept.
     */
    protected function shouldSample(Request $request, Response $response, array $payload): bool
    {
        $sampler = config('chronicle.sampling');

        if ($sampler === null) {
            return true;
        }

        $sampler = app()->make($sampler);

        if ($sampler instanceof Sampler) {
            return $sampler->shouldLog($request, $response, $payload);
        }

        throw InvalidSampler::doesNotImplementSampler($sampler);
    }

    /**
     * A 5xx is the app's own failure and needs attention, so it is an error. A 4xx is the client's
     * mistake, a warning because a spike of them points to a broken client or an attack.
     */
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
