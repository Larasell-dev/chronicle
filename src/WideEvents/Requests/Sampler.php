<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents\Requests;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface Sampler
{
    /**
     * @param  array<string, mixed>  $payload  The request event, flattened, as Chronicle would write it.
     */
    public function shouldLog(Request $request, Response $response, array $payload): bool;
}
