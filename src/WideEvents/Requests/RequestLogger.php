<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents\Requests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Larasell\Chronicle\Chronicle;
use Larasell\Chronicle\WideEvents\Enums\Level;
use Larasell\Chronicle\WideEvents\ErrorFields;
use Larasell\Chronicle\WideEvents\Requests\Exceptions\InvalidSampler;
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

    /**
     * @param  float  $startedAtSeconds  microtime(true) when the request started.
     */
    public function log(Request $request, Response $response, float $startedAtSeconds): void
    {
        $user = $request->user();
        $requestFields = [
            'request' => [
                // LogRequests' id in Context replaces this one; it only shows when the logger is called directly.
                'id' => substr(bin2hex(random_bytes(16)), 0, 16),
            ],
            'method' => $request->getMethod(),
            'url' => $request->fullUrl(),
            'route' => $this->routeName($request),
            'status' => $response->getStatusCode(),
            'duration_ms' => round((microtime(true) - $startedAtSeconds) * 1000, 2),
            'ip' => $request->ip(),
            'user' => [
                'id' => $user instanceof Authenticatable ? $user->getAuthIdentifier() : null,
            ],
        ];

        $exception = property_exists($response, 'exception') ? $response->exception : null;
        $errorFields = $exception instanceof Throwable ? ErrorFields::for($exception) : [];

        $event = $this->chronicle->event('request', $errorFields, defaults: $requestFields);

        if (! $this->shouldSample($request, $response, $event)) {
            return;
        }

        $this->chronicle->write($event, $this->levelForStatus($response->getStatusCode()));
    }

    /**
     * The route's name, or its uri when it has none. A route without a name in a group with a name
     * prefix, e.g. Route::name('shop.'), gets the prefix as its name, which names nothing.
     */
    protected function routeName(Request $request): ?string
    {
        $route = $request->route();
        $name = $route?->getName();

        return $name === null || $name === '' || str_ends_with($name, '.') ? $route?->uri() : $name;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function shouldSample(Request $request, Response $response, array $payload): bool
    {
        if (config('chronicle.sampling') === null) {
            return true;
        }

        $sampler = app()->make(Config::string('chronicle.sampling'));

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
