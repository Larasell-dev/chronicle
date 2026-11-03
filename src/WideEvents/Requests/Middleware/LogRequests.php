<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents\Requests\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Larasell\Chronicle\WideEvents\Requests\RequestId;
use Larasell\Chronicle\WideEvents\Requests\RequestLogger;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns the request its id, sends it back as X-Request-Id and logs the request event. Prepend it
 * to the global middleware, so the id is in Context before anything else can log.
 */
class LogRequests
{
    public const HEADER = 'X-Request-Id';

    public function __construct(
        protected RequestLogger $logger,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('chronicle.enabled', true)) {
            return $next($request);
        }

        $startedAtSeconds = microtime(true);

        $id = RequestId::fromHeader($request->headers->get(self::HEADER));
        Context::add(RequestId::CONTEXT_KEY, $id->value);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id->value);

        $this->logger->log($request, $response, $startedAtSeconds);

        return $response;
    }
}
