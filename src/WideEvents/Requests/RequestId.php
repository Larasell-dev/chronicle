<?php

namespace Larasell\Chronicle\WideEvents\Requests;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * The id of a request: the client's X-Request-Id when it is well-formed, otherwise a UUIDv7.
 * LogRequests sets it before anything else runs, so every log line of the request, the
 * request event and the response's X-Request-Id header carry the same id.
 */
final readonly class RequestId
{
    public const CONTEXT_KEY = 'request.id';

    private function __construct(
        public string $value,
    ) {}

    /**
     * The client's id when it is visible ASCII of at most 200 characters, which is what proxies
     * send and safe to log and echo back. Any other value, or none, gets a new UUIDv7, because
     * UUIDv7s sort by the time they were made, so the ids of a log sort like its lines.
     *
     * @internal
     */
    public static function fromHeader(?string $header): self
    {
        // A missing header is matched as '', which the pattern rejects, so it needs no own branch.
        if (preg_match('/^[\x21-\x7E]{1,200}$/D', $header ?? '') === 1) {
            return new self($header);
        }

        return new self(Str::uuid7()->toString());
    }

    /**
     * The current request's id, or null outside a request.
     */
    public static function current(): ?string
    {
        $id = Context::get(self::CONTEXT_KEY);

        return is_string($id) ? $id : null;
    }
}
