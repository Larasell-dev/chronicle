<?php

namespace Larasell\Chronicle\WideEvents\Requests;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides which request events Chronicle keeps, so an app can cut log volume without losing the
 * requests that matter. Set the class in chronicle.sampling.
 */
interface Sampler
{
    /**
     * Called after the response, with the finished event as $payload, flattened into dotted keys,
     * so the decision can use the status, the duration and any error. Return true to keep it.
     */
    public function shouldLog(Request $request, Response $response, array $payload): bool;
}
