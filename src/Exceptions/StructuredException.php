<?php

namespace Larasell\Chronicle\Exceptions;

interface StructuredException
{
    public function errorCode(): ?string;

    public function errorWhy(): ?string;

    public function errorFix(): ?string;

    public function errorLink(): ?string;
}
