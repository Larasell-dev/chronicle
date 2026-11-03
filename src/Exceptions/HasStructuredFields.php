<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Exceptions;

/**
 * The fields and getters of a StructuredException. The inline Structured*Exception classes use it
 * directly, because their constructors take the fields, so they can't use the structured()
 * factory of HasStructuredException, which builds the exception with Exception's constructor.
 *
 * @internal
 */
trait HasStructuredFields
{
    private ?string $errorCode = null;

    private ?string $errorWhy = null;

    private ?string $errorFix = null;

    private ?string $errorLink = null;

    /**
     * Sets the fields, once, while the exception is built: in its constructor or a static
     * factory. They are private, so code that catches the exception can only read them.
     */
    protected function withError(string $errorCode, ?string $why = null, ?string $fix = null, ?string $link = null): static
    {
        $this->errorCode = $errorCode;
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
