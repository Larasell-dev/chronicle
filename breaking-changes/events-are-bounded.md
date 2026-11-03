# Every event is bounded: strings, keys, nesting and field count have limits

Chronicle used to log a string field or a nested array at whatever size the app passed. One large
field in `Context` could make every log line of a request megabytes long. Lists were the only
values that had a limit, and they were cut at byte 1,000 with `substr()`, which could leave
invalid JSON or split a UTF-8 character. Now every part of an event has a limit. Each byte limit
counts the bytes a value takes in the log line, escapes included, so a string of quotes or control
characters keeps fewer characters than one of letters, and no event's line is longer than
`Chronicle::EVENT_BYTES_MAX` bytes, about 311 KB:

| What | Limit | What happens past it |
|---|---|---|
| A string value | 1,000 bytes (`Chronicle::VALUE_BYTES_MAX`) | Cut to the longest prefix that fits, on a UTF-8 character boundary |
| A list, stored as JSON | 1,000 bytes | Whole elements are left out, so it stays valid JSON, and `<key>_count` holds the full count |
| A key | 200 bytes | Cut on a character boundary |
| Nesting | 32 levels | Deeper arrays are stored as one JSON value |
| Fields per event | 256 | Fields only in `Context` are dropped first, latest first, and `chronicle.fields_dropped` holds how many |

## Who is affected

- Apps that log strings longer than 1,000 bytes, such as request bodies, SQL or stack traces,
  and read them back in full.
- Apps that parse list fields. A cut list used to be truncated JSON; now it's valid JSON with
  fewer elements, plus a `<key>_count` field.
- Apps with more than 256 fields in one event, Context included.

Apps whose events stay within these limits see no difference, except that log lines now contain
non-ASCII characters as they are, e.g. `ü` instead of `\u00fc`, and `/` instead of `\/` inside
list values. JSON readers decode both the same way.

## How to upgrade

Nothing to change in code. To keep more of a long value, log the parts you need as separate
fields, or log a reference to the full value:

```php
use Larasell\Chronicle\WideEvents\Chronicle;

// Before: the whole payload, any size
app(Chronicle::class)->record('webhook', ['payload' => $request->getContent()]);

// After: the fields you query on, and a pointer to the full body
app(Chronicle::class)->record('webhook', [
    'webhook.provider' => 'stripe',
    'webhook.event' => $request->input('type'),
    'webhook.body_path' => $storedPath,
]);
```

When you read a list field, check `<key>_count` to see if elements were left out:

```json
{"type":"tags","user.tags":"[\"tag-0\",\"tag-1\",…]","user.tags_count":200}
```
