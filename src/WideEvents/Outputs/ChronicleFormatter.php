<?php

namespace Larasell\Chronicle\WideEvents\Outputs;

use Monolog\Formatter\LineFormatter;

/**
 * Writes each event as one JSON line, so the log file can be read line by line. The message is
 * already the complete JSON event, so no date, level or channel is added around it.
 *
 * @internal
 */
class ChronicleFormatter extends LineFormatter
{
    public function __construct()
    {
        parent::__construct(
            format: '%message%'.PHP_EOL,
            // Allowing inline line breaks would make LineFormatter turn the escaped \n in a JSON
            // message back into real newlines, splitting one event across several lines.
            allowInlineLineBreaks: false,
            ignoreEmptyContextAndExtra: true,
        );
    }
}
