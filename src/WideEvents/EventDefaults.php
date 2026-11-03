<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents;

/**
 * The defaults of an event: fields Chronicle measured itself, such as a request's user.id, which
 * apps may replace through Context. A type of their own, not a second array, so
 * Chronicle::recordRequest() can't be called with the fields and the defaults swapped.
 *
 * @internal
 */
final readonly class EventDefaults
{
    /**
     * @param  array<mixed>  $fields
     */
    public function __construct(
        public array $fields,
    ) {}
}
