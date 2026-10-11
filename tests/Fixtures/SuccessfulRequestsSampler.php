<?php

namespace Larasell\Chronicle\Tests\Fixtures;

use Illuminate\Http\Request;
use Larasell\Chronicle\Sampler;
use Symfony\Component\HttpFoundation\Response;

class SuccessfulRequestsSampler implements Sampler
{
    public function shouldLog(Request $request, Response $response, array $payload): bool
    {
        return $payload['status'] < 400;
    }
}