<?php

namespace Larasell\Chronicle\Tests\Fixtures;

use Illuminate\Http\Request;
use Larasell\Chronicle\Sampler;
use Symfony\Component\HttpFoundation\Response;

class RecordingSampler implements Sampler
{
    public static array $seen = [];

    public function shouldLog(Request $request, Response $response, array $payload): bool
    {
        static::$seen = [$request->getMethod(), $response->getStatusCode(), $payload['url']];

        return true;
    }
}
