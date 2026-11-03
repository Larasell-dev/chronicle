<?php

namespace Larasell\Chronicle\Exceptions;

use Throwable;

trait HasStructuredException
{
    public ?string $errorCode = null;

    public ?string $errorWhy = null;

    public ?string $errorFix = null;

    public ?string $errorLink = null;

    /**
     * Builds the exception for a named constructor, such as PaymentDeclined::byIssuer(). Named
     * constructors keep the code, why, fix and link next to the one situation they describe.
     * PHP's integer exception code is always 0, because the string code replaces it.
     */
    protected static function structured(
        string $code,
        string $message,
        ?string $why = null,
        ?string $fix = null,
        ?string $link = null,
        ?Throwable $previous = null,
    ): static {
        $exception = new static($message, 0, $previous);

        return $exception->withError($code, $why, $fix, $link);
    }

    /**
     * Sets the structured fields on an exception that already exists. structured() and the
     * constructors of the inline Structured*Exception classes both use it, because a constructor
     * cannot call structured(), which would build a second exception.
     */
    protected function withError(string $code, ?string $why = null, ?string $fix = null, ?string $link = null): static
    {
        $this->errorCode = $code;
        $this->errorWhy = $why;
        $this->errorFix = $fix;
        $this->errorLink = $link;

        return $this;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorWhy(): ?string
    {
        return $this->errorWhy;
    }

    public function errorFix(): ?string
    {
        return $this->errorFix;
    }

    public function errorLink(): ?string
    {
        return $this->errorLink;
    }
}
