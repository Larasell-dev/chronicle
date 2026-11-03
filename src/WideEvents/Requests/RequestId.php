<?php

declare(strict_types=1);

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

    /**
     * The most characters a client's id may have. The id is only ever visible ASCII, so this is
     * also its byte count. Long enough for any proxy's id, short enough to log on every line.
     */
    private const LENGTH_MAX = 200;

    private function __construct(
        public string $value,
    ) {}

    /**
     * The client's id when it is visible ASCII of at most LENGTH_MAX characters, which is what proxies
     * send and safe to log and echo back. Any other value, or none, gets a new UUIDv7.
     *
     * @internal
     */
    public static function fromHeader(?string $header): self
    {
        // No header matches like an empty one: the pattern needs at least one character.
        if (preg_match('/^[\x21-\x7E]{1,'.self::LENGTH_MAX.'}$/D', $header ?? '') !== 1) {
            return new self(Str::uuid7()->toString());
        }

        return new self($header);
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
