<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents\Requests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Larasell\Chronicle\WideEvents\Chronicle;
use Larasell\Chronicle\WideEvents\Enums\Level;
use Larasell\Chronicle\WideEvents\ErrorFields;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @internal
 */
class RequestLogger
{
    public function __construct(
        protected Chronicle $chronicle,
        // Null keeps every request event.
        protected ?Sampler $sampler,
    ) {}

    /**
     * @param  int  $startedAtNanoseconds  hrtime(true) when the request started. hrtime is
     *                                     monotonic, unlike the wall clock, which NTP or a resumed
     *                                     VM can set back or forward during a request.
     */
    public function log(Request $request, Response $response, int $startedAtNanoseconds): void
    {
        $durationNanoseconds = hrtime(true) - $startedAtNanoseconds;

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
            'duration_ms' => round($durationNanoseconds / 1_000_000, 2),
            'ip' => $request->ip(),
            'user' => [
                'id' => $user instanceof Authenticatable ? $user->getAuthIdentifier() : null,
            ],
        ];

        $exception = property_exists($response, 'exception') ? $response->exception : null;
        $errorFields = $exception instanceof Throwable ? ErrorFields::for($exception) : [];

        $event = $this->chronicle->event('request', $errorFields, defaults: $requestFields);

        // Without a sampler every request event is kept.
        if ($this->sampler?->shouldLog($request, $response, $event) === false) {
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
        $name = $route?->getName() ?? '';

        if ($name === '') {
            return $route?->uri();
        }

        if (str_ends_with($name, '.')) {
            return $route->uri();
        }

        return $name;
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
