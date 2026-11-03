# Chronicle redacts the values of secret keys

Chronicle used to log every value as it was, so a `password`, `api_key` or `token` that reached an
event through fields, `Context`, an exception's `context()` or the query was written to the log
file and sent to PostHog. Chronicle now logs the value of every key that contains a word from the
new `chronicle.redact` list as `"[redacted]"`, wherever the key is in an event, lists and objects
included. The default list covers passwords, secrets, tokens, API keys, cookies, signatures and
card numbers. Keys match by whole words, in any case: `token` matches `access_token`,
`X-Csrf-Token` and `apiToken`, but not `tokens`. An event with hidden values lists their paths in
`chronicle.redacted`, e.g. `["user.password","items.0.token"]`.

## Who is affected

Apps that log a value under a key such as `token`, `secret`, `signature` or `password` and read it
from the log, e.g. a `token_type` or `password_changed_at` field. Apps with a published
`config/chronicle.php` get the default list too, since the config merges it in.

## How to upgrade

Rename fields you need to keep, or set your own list in `config/chronicle.php`:

```php
// Before: logged as is
Context::add('password_changed_at', $user->password_changed_at);

// After: logged as "[redacted]", so give it a key without the word
Context::add('user.days_since_reset', $user->password_changed_at->diffInDays());
```

```php
// config/chronicle.php: your own list replaces the default one
'redact' => [
    'password', 'secret', 'token', 'api_key', 'apikey', 'authorization', 'cookie',
    'credential', 'credentials', 'private_key', 'signature', 'sig', 'card_number',
    'cvc', 'cvv', 'iban',
],
```
