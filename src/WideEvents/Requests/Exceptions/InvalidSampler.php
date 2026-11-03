<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents\Requests\Exceptions;

use Larasell\Chronicle\Exceptions\HasStructuredException;
use Larasell\Chronicle\Exceptions\StructuredException;
use Larasell\Chronicle\WideEvents\Requests\Sampler;
use LogicException;

/**
 * @internal
 */
final class InvalidSampler extends LogicException implements StructuredException
{
    use HasStructuredException;

    public static function doesNotImplementSampler(mixed $sampler): self
    {
        return self::structured(
            errorCode: 'chronicle.sampler.invalid',
            message: 'chronicle.sampler must resolve to an instance of '.Sampler::class,
            why: 'chronicle.sampler resolved to '.get_debug_type($sampler),
            fix: 'Implement the '.Sampler::class.' contract or remove the chronicle.sampler config',
        );
    }
}
