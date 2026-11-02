<?php

namespace Larasell\Chronicle\Requests\Exceptions;

use Larasell\Chronicle\ChronicleException;
use Larasell\Chronicle\Requests\Sampler;

final class InvalidSampler extends ChronicleException
{
    public static function doesNotImplementSampler(object $sampler): self
    {
        return self::structured(
            code: 'chronicle.sampling.invalid-sampler',
            message: 'chronicle.sampling must resolve to an instance of '.Sampler::class,
            why: 'chronicle.sampling resolved to '.$sampler::class,
            fix: 'Implement the '.Sampler::class.' contract or remove the chronicle.sampling config',
        );
    }
}
