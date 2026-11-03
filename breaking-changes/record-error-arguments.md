# `recordError()` takes its arguments in the same order as `record()`, with Chronicle's own `Level`

`recordError()` took the level second and the fields third, the reverse of `record()`, and named
the fields `$context` instead of `$fields`. Both methods now take fields second and the level last.
Levels are now Chronicle's own `Larasell\Chronicle\WideEvents\Enums\Level` enum, so apps no longer need Monolog to
pick one. Level names as strings still work, in any case; an unknown name now throws a structured
`chronicle.level.unknown` exception instead of Monolog's `ValueError`.

## Who is affected

- Calls to `recordError()` that pass a level, or fields by the name `context:`.
- Calls to `record()` or `recordError()` that pass a `Monolog\Level` case. Strings such as
  `'warning'` keep working.

Apps that call `recordError($exception)` and `record($type, $fields)` without a level are not
affected.

## How to upgrade

```php
// Before
use Monolog\Level;

app(Chronicle::class)->recordError($exception, Level::Warning, ['order.id' => 7]);
app(Chronicle::class)->recordError($exception, context: ['order.id' => 7]);
app(Chronicle::class)->record('inventory.low', ['sku' => $sku], Level::Warning);

// After
use Larasell\Chronicle\WideEvents\Enums\Level;

app(Chronicle::class)->recordError($exception, ['order.id' => 7], Level::Warning);
app(Chronicle::class)->recordError($exception, fields: ['order.id' => 7]);
app(Chronicle::class)->record('inventory.low', ['sku' => $sku], Level::Warning);

// A level without fields: name it
app(Chronicle::class)->recordError($exception, level: 'warning');
```
