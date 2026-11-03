<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Tests\Fixtures;

use Illuminate\Http\Request;
use Larasell\Chronicle\WideEvents\Requests\Sampler;
use Symfony\Component\HttpFoundation\Response;

class SuccessfulRequestsSampler implements Sampler
{
    public function shouldLog(Request $request, Response $response, array $event): bool
    {
        return $event['status'] < 400;
    }
}
