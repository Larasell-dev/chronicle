<?php

namespace Larasell\Chronicle\WideEvents\Requests\Exceptions;

use Larasell\Chronicle\Errors\HasStructuredError;
use Larasell\Chronicle\Errors\StructuredError;
use Larasell\Chronicle\WideEvents\Requests\Sampler;
use LogicException;

final class InvalidSampler extends LogicException implements StructuredError
{
    use HasStructuredError;

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
