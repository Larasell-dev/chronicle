# Context can no longer replace the request fields Chronicle measured

A request's event used to take any `Context` field with the same key as a request field, so a
package that put `status` or `route` into Context changed the event of every request, also the
requests of other packages. The fields Chronicle measured itself, `request.id`, `method`, `url`,
`route`, `status`, `duration_ms`, `ip` and `user.id`, now always win, and so do an event's
`timestamp` and `type`. Context can still fill in a request field that is `null`.

## Who is affected

Apps that use `Context` to replace a request field that Chronicle measured, e.g. a `user.id` set
in Context while a user is signed in through the default guard, or any Context key named
`timestamp` or `type`.

Apps that only add their own keys to Context, or fill in `user.id` for guests, e.g. for customers
signed in through another guard, are not affected.

## How to upgrade

Keep a measured value under a key of its own:

```php
use Illuminate\Support\Facades\Context;

// Before: replaced the id of the signed-in user
Context::add('user.id', $customer->id);

// After
Context::add('customer.id', $customer->id);
```
