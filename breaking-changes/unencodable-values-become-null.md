# Values JSON can't encode become `null`, instead of costing the whole event

A field `json_encode` couldn't encode, such as `NAN`, `INF`, a resource, or an object whose
`jsonSerialize()` throws, used to make the whole event fail to encode, and Chronicle wrote an
empty line. The event was lost without a trace. Now only that field becomes `null`, and
`chronicle.unencodable` says which fields and why, as a JSON object:

```json
{"type":"checkout","order.id":7,"conversion":null,"ratio":null,
 "chronicle.unencodable":"{\"conversion\":\"NAN\",\"ratio\":\"INF\"}"}
```

In a list, only the bad elements become `null`: `[1,null,2]`.

## Who is affected

- Apps that log floats that can be `NAN` or `INF`, e.g. a ratio divided by zero, or resources
  such as file handles. Those events now show up, with `null` in place of the value.
- Apps that look for empty lines in `chronicle.log` to spot failed events. Search for
  `chronicle.unencodable` instead.
- The posthog channel: a message that isn't a JSON object now throws `chronicle.posthog.invalid-event`
  in debug mode. Outside debug mode it is sent with `chronicle.decode_error`.

Apps that only log strings, ints, bools and arrays of them are not affected.

## How to upgrade

Nothing is required. To find the events that had a bad value:

```bash
grep '"chronicle.unencodable"' storage/logs/chronicle.log
```

To keep the information, log the value in a form JSON can hold:

```php
// Before: NAN when there were no visits
app(Chronicle::class)->record('report', ['conversion' => $orders / $visits]);

// After
app(Chronicle::class)->record('report', ['conversion' => $visits > 0 ? $orders / $visits : null]);
```
