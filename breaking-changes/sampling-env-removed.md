# `CHRONICLE_SAMPLING` is no longer read

A sampler is a class name, which belongs in code that is reviewed and versioned with the class,
not in an environment variable that can name a class the deployed code does not have.

## Who is affected

Apps that set `CHRONICLE_SAMPLING` in their environment. Without the change, they log every
request again. Apps that set `sampling` in `config/chronicle.php` without `env()` are not
affected.

## How to upgrade

Publish the config file if you haven't, set the sampler class in it, and remove
`CHRONICLE_SAMPLING` from your environment:

```bash
php artisan vendor:publish --tag=chronicle.config
```

```php
// config/chronicle.php

// Before
'sampling' => env('CHRONICLE_SAMPLING'),

// After
'sampling' => App\Logging\KeepFailuresAndSlowRequests::class,
```
