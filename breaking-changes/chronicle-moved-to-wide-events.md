# `Chronicle` is now `Larasell\Chronicle\WideEvents\Chronicle`

The package has two features: structured exceptions in `Larasell\Chronicle\Exceptions`, and wide
events in `Larasell\Chronicle\WideEvents`. `Chronicle` records wide events, so it now lives with
the rest of them. The new `Level` enum lives there too, as `Larasell\Chronicle\WideEvents\Enums\Level`.

## Who is affected

Every app that imports or type-hints `Larasell\Chronicle\Chronicle`, e.g. to call `record()` or
`recordError()`. There is no alias for the old name. Apps that only use the structured exceptions
or the `LogRequests` middleware are not affected.

## How to upgrade

Change the import:

```php
// Before
use Larasell\Chronicle\Chronicle;

// After
use Larasell\Chronicle\WideEvents\Chronicle;
```

To update every file at once:

```bash
grep -rl 'Larasell\\Chronicle\\Chronicle\b' app routes bootstrap config tests \
  | xargs sed -i 's/Larasell\\Chronicle\\Chronicle\b/Larasell\\Chronicle\\WideEvents\\Chronicle/g'
```
