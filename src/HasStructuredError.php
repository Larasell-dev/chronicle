<?php

namespace Larasell\Chronicle;

use Throwable;

trait HasStructuredError
{
    public ?string $errorCode = null;

    public ?string $errorWhy = null;

    public ?string $errorFix = null;

    public ?string $errorLink = null;

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
