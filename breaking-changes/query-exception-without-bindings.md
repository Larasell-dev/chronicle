# A failed query's `error.message` no longer contains its values

Laravel's `QueryException` puts the query's bindings into its message, e.g.
`insert into users (email, password) values (jane@example.com, $2y$12$…)`, so Chronicle logged
emails, password hashes and tokens with every failed query. Chronicle now logs the SQL with its
`?` placeholders instead. The database's own message, which comes first, is unchanged and can
still quote a value, e.g. the entry that broke a unique index.

```text
// Before
SQLSTATE[HY000]: General error (Connection: mysql, SQL: insert into users (email) values (jane@example.com))

// After
SQLSTATE[HY000]: General error (Connection: mysql, SQL: insert into users (email) values (?))
```

## Who is affected

Apps that read the bindings of failed queries from `error.message` in their logs or in PostHog.
Error events of other exceptions are not affected.

## How to upgrade

Log the values you need yourself, under keys that aren't secret:

```php
// Before: the email was in error.message
$user->save();

// After
Context::add('signup.email_domain', Str::after($user->email, '@'));
$user->save();
```
