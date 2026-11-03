<?php

namespace Larasell\Chronicle\WideEvents\Outputs;

use Monolog\Formatter\LineFormatter;

/**
 * @internal
 */
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
