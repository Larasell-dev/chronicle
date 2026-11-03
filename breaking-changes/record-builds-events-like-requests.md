# `record()` flattens fields and adds `Context`

`record()` now builds its event like request and error events do. It flattens nested arrays into
dotted keys and adds every `Context` field, so your own events carry the same fields as the
requests they happen in.

## Who is affected

Apps that call `Chronicle::record()` with nested fields, or that read those events and expect
nested objects or no `Context` fields. Apps that call `Chronicle::flattenContext()`, which is
removed. Apps that only use `LogRequests` and `recordError()` are not affected.

## How to upgrade

Read nested fields by their dotted key. A field you pass still wins over a `Context` field with
the same key.

```php
Context::add('user', ['id' => 7]);

app(Chronicle::class)->record('order.paid', ['order' => ['id' => 42]]);
```

```json
// Before
{"timestamp": "...", "type": "order.paid", "order": {"id": 42}}

// After
{"timestamp": "...", "type": "order.paid", "user.id": 7, "order.id": 42}
```

Instead of calling `flattenContext()` before `record()`, pass the nested array to `record()`.
