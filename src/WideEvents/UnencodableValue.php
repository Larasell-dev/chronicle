<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents;

/**
 * Stands in for a value json_encode can't encode, between Chronicle::flatten() and the end of
 * Chronicle::event(). flatten() marks the value where it finds it, and event() only reports the
 * marks still in the event after merging, so a bad value that a later field replaced, or that the
 * field limit dropped, isn't reported.
 *
 * @internal
 */
final readonly class UnencodableValue
{
    public function __construct(
        // Why the value can't be encoded, e.g. NAN or "resource (stream): Type is not supported".
        public string $reason,
        // What the event holds instead: null, or for an array the JSON of its encodable elements.
        public ?string $replacement,
    ) {}
}
