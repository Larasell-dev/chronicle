<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Tests\Fixtures;

use Illuminate\Http\Request;
use Larasell\Chronicle\WideEvents\Requests\Sampler;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps every tenth request event. It counts in its own state, so it only works when Chronicle
 * builds it once rather than once per request.
 */
final class EveryTenthRequestSampler implements Sampler
{
    private int $requestCount = 0;

    public function shouldLog(Request $request, Response $response, array $event): bool
    {
        $this->requestCount++;

        return $this->requestCount % 10 === 0;
    }
}
