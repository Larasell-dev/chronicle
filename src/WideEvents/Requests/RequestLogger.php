<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents\Requests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Larasell\Chronicle\Support\Assert;
use Larasell\Chronicle\WideEvents\Chronicle;
use Larasell\Chronicle\WideEvents\Enums\Level;
use Larasell\Chronicle\WideEvents\ErrorFields;
use Larasell\Chronicle\WideEvents\EventDefaults;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @internal
 */
class RequestLogger
{
    /** The most query values a request event keeps. */
    private const QUERY_FIELDS_MAX = 32;

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

        // A monotonic clock can't run backwards, so only a start that isn't from hrtime(true) can
        // make this negative: a bug, never a duration to log. Outside debug mode the request still
        // gets its event, without a duration, and the event says why.
        $durationMessage = "The request's start lies ".(-$durationNanoseconds).' ns in the future: RequestLogger::log() needs hrtime(true) from the start of the request';
        $durationKnown = Assert::that($durationNanoseconds >= 0, 'chronicle.request.negative-duration', $durationMessage);
        $assertionFailures = $durationKnown ? [] : ['chronicle.request.negative-duration' => $durationMessage];

        $user = $request->user();
        $requestFields = [
            'request' => [
                // The id LogRequests put in Context, the one source of a request's id. Null when the
                // logger runs without LogRequests, since an id of its own would match nothing.
                'id' => RequestId::current(),
            ],
            'method' => $request->getMethod(),
            'url' => $this->url($request),
            ...$this->queryFields($request),
            'route' => $this->routeName($request),
            ...$this->routeParameterFields($request),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationKnown ? round($durationNanoseconds / 1_000_000, 2) : null,
            'ip' => config('chronicle.log_ip') ? $request->ip() : null,
            'user' => [
                'id' => $user instanceof Authenticatable ? $user->getAuthIdentifier() : null,
            ],
        ];

        $exception = property_exists($response, 'exception') ? $response->exception : null;
        $errorFields = $exception instanceof Throwable ? ErrorFields::for($exception) : [];

        $this->chronicle->recordRequest(
            requestFields: new EventDefaults($requestFields),
            errorFields: $errorFields,
            level: $this->levelForStatus($response->getStatusCode()),
            // Without a sampler every request event is kept.
            shouldLog: fn (array $event): bool => $this->sampler?->shouldLog($request, $response, $event) ?? true,
            assertionFailures: $assertionFailures,
        );
    }

    /**
     * The url without its query, which queryFields() logs, and with the matched route's template
     * as its path, e.g. /reset-password/{token}, so values in the path such as tokens are only
     * logged in routeParameterFields(), where they can be redacted by key. A request no route
     * matched, e.g. a 404, keeps its path, since nothing tells which part of it is a value.
     */
    protected function url(Request $request): string
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return $request->url();
        }

        $path = trim($route->uri(), '/');

        if ($path === '') {
            return $request->root();
        }

        return $request->root().'/'.$path;
    }

    /**
     * The values of the matched route's parameters as they were in the path, one field each, e.g.
     * route_params.token, before route model binding turned them into models. Only the parameters
     * in the route's uri: Laravel adds a route's defaults to its parameters too, e.g. the view and
     * data of Route::view(), which are code, not part of the path. No field when the route has no
     * such parameters or no route matched.
     *
     * @return array<string, mixed>
     */
    protected function routeParameterFields(Request $request): array
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return [];
        }

        $original = $route->originalParameters();
        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            if (is_string($name) && array_key_exists($name, $original)) {
                $parameters[$name] = $original[$name];
            }
        }

        if ($parameters === []) {
            return [];
        }

        return ['route_params' => $parameters];
    }

    /**
     * The query as a query array, flattened to one field per value, e.g. query.filter.status, or no
     * field when the url has no query. The client chooses the query, so at most QUERY_FIELDS_MAX
     * values are kept, and query_count holds how many there are when some were left out: without
     * that bound, a long query would push the app's own fields out of the event.
     *
     * @return array<string, mixed>
     */
    protected function queryFields(Request $request): array
    {
        $values = Arr::dot($request->query->all());

        if ($values === []) {
            return [];
        }

        $fields = ['query' => array_slice($values, 0, self::QUERY_FIELDS_MAX, true)];

        if (count($values) > self::QUERY_FIELDS_MAX) {
            $fields['query_count'] = count($values);
        }

        return $fields;
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
