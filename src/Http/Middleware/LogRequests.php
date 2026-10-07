<?php

namespace Larasell\Chronicle\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Larasell\Chronicle\RequestLogger;
use Symfony\Component\HttpFoundation\Response;

class LogRequests
{
    public function __construct(
        protected RequestLogger $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        $response = $next($request);

        if (config('chronicle.enabled', true)) {
            $this->logger->log($request, $response, $startTime);
        }

        return $response;
    }
}
