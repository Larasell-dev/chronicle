<?php

namespace Larasell\Chronicle;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface Sampler
{
    public function shouldLog(Request $request, Response $response, array $payload): bool;
}
