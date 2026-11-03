# Chronicle's channels are registered once, when the app boots

Chronicle used to copy every channel in `chronicle.channels` into `logging.channels` on every
event, so a change to `chronicle.channels` at runtime took effect on the next event. That cost
work on every event, and Laravel caches each logger on first use anyway, so changes to a channel
already used were ignored. Chronicle now registers the channels once, before any provider boots.
`chronicle.default`, the channel to write to, is still read on every event.

## Who is affected

Code that changes `chronicle.channels` after the app booted: in a provider's `boot()`, a
middleware, a job or a test.

Apps that only set `chronicle.channels` in `config/chronicle.php`, and switch channels with
`CHRONICLE_CHANNEL` or `chronicle.default`, are not affected.

## How to upgrade

Define channels in `config/chronicle.php`, or set them in a provider's `register()`, which runs
before boot:

```php
// Before: in AppServiceProvider::boot()
config()->set('chronicle.channels.audit', ['driver' => 'single', 'path' => storage_path('logs/audit.log')]);

// After: in AppServiceProvider::register()
config()->set('chronicle.channels.audit', ['driver' => 'single', 'path' => storage_path('logs/audit.log')]);
```

In tests with Orchestra Testbench, set them in `defineEnvironment()`. Then pick one per test with
`chronicle.default`:

```php
protected function defineEnvironment($app): void
{
    config()->set('chronicle.channels.test', ['driver' => 'custom', 'via' => fn () => new Logger('test', [new TestHandler])]);
}

// In a test
config()->set('chronicle.default', 'test');
```
