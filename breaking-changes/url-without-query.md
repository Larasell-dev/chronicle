# A request's `url` has no query and the route's template as its path

`url` used to be the full url, query included, so tokens and signed-url signatures in the query
were logged as they were. `url` is now the url without the query, and each query value is a field
of its own, `query.<name>`, so [redaction](secret-keys-redacted.md) can hide the values of secret
keys. A request event keeps at most 32 query values; past that, `query_count` holds how many the
query has. A url without a query gets no `query` fields.

Values in the path, such as a password reset token, were logged as they were too. When a route
matched, the path in `url` is now the route's template, and each route parameter is a field of its
own, `route_params.<name>`, with its value from the path, so the same redaction applies. A request
that matches no route, such as a 404, keeps its path.

```json
// Before: /orders?page=2&token=abc
{"url": "https://shop.test/orders?page=2&token=abc"}

// After
{"url": "https://shop.test/orders", "query.page": "2", "query.token": "[redacted]"}

// Before: /reset-password/9f2c1a
{"url": "https://shop.test/reset-password/9f2c1a"}

// After
{"url": "https://shop.test/reset-password/{token}", "route_params.token": "[redacted]"}
```

## Who is affected

Dashboards, alerts, samplers and queries that read the query or a path value from `url`, e.g. in
PostHog or with `$event['url']` in a `Sampler`. Grouping by `url` now groups by route, which
merges the requests of every order, user or token.

Apps that only use `route`, or `url` of routes without parameters, are not affected.

## How to upgrade

Read query values from their own fields:

```php
// Before
public function shouldLog(Request $request, Response $response, array $event): bool
{
    return str_contains($event['url'], 'debug=1');
}

// After
public function shouldLog(Request $request, Response $response, array $event): bool
{
    return ($event['query.debug'] ?? null) === '1';
}
```

Read path values from `route_params`:

```php
// Before: /orders/7
$orderId = (int) basename($event['url']);

// After
$orderId = (int) $event['route_params.order'];
```
