<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Tests\Fixtures;

class NotASampler
{
    public function shouldLog(): bool
    {
        return true;
    }
}
