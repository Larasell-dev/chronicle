<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Tests\Fixtures;

use Illuminate\Http\Request;
use Larasell\Chronicle\WideEvents\Requests\Sampler;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps every request it is asked about. On the instance, not in a static, so what it saw ends
 * with the test.
 */
final class RecordingSampler implements Sampler
{
    /** @var list<array{string, int, mixed}> The method, status and url of each call, in order. */
    public array $seen = [];

    public function shouldLog(Request $request, Response $response, array $event): bool
    {
        $this->seen[] = [$request->getMethod(), $response->getStatusCode(), $event['url']];

        return true;
    }
}
