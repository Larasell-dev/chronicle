<?php

namespace Larasell\Chronicle\Logging;

use Monolog\Formatter\LineFormatter;

class ChronicleFormatter extends LineFormatter
{
    public function __construct()
    {
        parent::__construct(
            format: '%message%'.PHP_EOL,
            allowInlineLineBreaks: true,
            ignoreEmptyContextAndExtra: true,
        );
    }
}
