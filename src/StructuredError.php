<?php

namespace Larasell\Chronicle;

interface StructuredError
{
    public function errorCode(): ?string;

    public function errorWhy(): ?string;

    public function errorFix(): ?string;

    public function errorLink(): ?string;
}
