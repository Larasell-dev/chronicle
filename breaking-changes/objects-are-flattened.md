# Objects in an event are flattened into dotted fields, like arrays

An object used to be stored as one value, whole: a `Collection`, a DTO or a `JsonSerializable`
was written at whatever size its JSON had, past every limit of the event. Now Chronicle first
turns an object into plain data, and that data follows the same rules as arrays:

| Object | Turned into |
|---|---|
| `JsonSerializable` | what `jsonSerialize()` returns |
| `Arrayable`, e.g. a `Collection` or a model | what `toArray()` returns |
| `stdClass` | its properties |
| Any other object | its public properties, through `json_encode` |

So an associative result becomes dotted fields, a list becomes one bounded JSON value with a
`<key>_count` when elements were left out, and a string is cut to 1,000 bytes.

## Who is affected

Apps that pass objects as fields or put them in `Context`, and parse those fields:

```php
app(Chronicle::class)->record('checkout', ['customer' => (object) ['id' => 7, 'tier' => 'gold']]);
```

```json
// Before
{"type":"checkout","customer":{"id":7,"tier":"gold"}}

// After
{"type":"checkout","customer.id":7,"customer.tier":"gold"}
```

Apps that only log scalars and arrays are not affected.

## How to upgrade

Query the dotted fields instead of the nested object, e.g. `customer.tier` rather than
`customer->tier`. A `jsonSerialize()` or `toArray()` that throws no longer costs the event: the
field becomes `null` and `chronicle.unencodable` names the method that threw.
