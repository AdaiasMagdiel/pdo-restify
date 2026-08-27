# Getting started

## Install

```bash
composer require adaiasmagdiel/pdo-restify
```

Requires PHP 8.2+ and the `pdo` extension (plus `pdo_mysql` or `pdo_sqlite`,
depending on which database you target).

## The four pieces

Every pdo-restify setup is the same four steps, in this order:

1. **Get a `PDO`.** Either build one with `Connection::make()`, or bring one
   your application already has.
2. **Describe a `Resource`.** A resource wraps one database table: which
   columns are exposed, and what its primary key column is called.
3. **Enable operations with policies.** Call `->allow()` for each of
   `select`, `insert`, `update`, `delete` you want to expose, each with a
   closure that scopes which rows the caller can touch (or no closure, if you
   want that operation wide open — see
   [Resources & security model](03-resources-and-security.md)).
4. **Register the resource on an `Api` and call `handle()`.** `Api::handle()`
   takes a `Request` and a context array, and returns a `Response` — plain
   data in, plain data out, no I/O.

## A complete example

```php
use AdaiasMagdiel\PdoRestify\Api;
use AdaiasMagdiel\PdoRestify\Connection;
use AdaiasMagdiel\PdoRestify\Http\Request;
use AdaiasMagdiel\PdoRestify\Resource;

// 1. Connection
$pdo = Connection::make('sqlite', __DIR__ . '/database.sqlite');

// 2. Resource
$posts = (new Resource('posts'))
    ->columns(['id', 'title', 'body', 'user_id']);

// 3. Policies — here every operation is scoped to the current user
$scopedToCurrentUser = fn (array $context): array => ['user_id' => $context['user_id']];

$posts
    ->allow('select', $scopedToCurrentUser)
    ->allow('insert', $scopedToCurrentUser)
    ->allow('update', $scopedToCurrentUser)
    ->allow('delete', $scopedToCurrentUser);

// 4. Api
$api = (new Api($pdo))->register($posts);

$request = new Request(
    method: 'GET',
    path: '/posts',
    query: ['title' => 'like.*hello*', 'order' => 'id.desc', 'limit' => '10'],
);

$response = $api->handle($request, context: ['user_id' => 42]);

// $response->status -> 200
// $response->body    -> rows from `posts` where user_id = 42 and title LIKE '%hello%',
//                        ordered by id desc, at most 10 rows.
```

Run this against a real HTTP request and you have a REST endpoint — but note
that nothing above touched `$_SERVER`, `php://input`, or `header()`. That's
deliberate: pdo-restify doesn't listen on anything by itself. Building the
`Request` from a real incoming request, and writing the `Response` back out,
is the host application's job. See
[Integrating with a framework](05-integrating-with-frameworks.md) for how
that bridge looks in practice.

## Where the context comes from

The second argument to `handle()` — `$context` — is whatever your
application decides it is. pdo-restify never inspects it beyond handing it,
as-is, to each policy closure. In practice it's usually something like
`['user_id' => $currentUser->id, 'role' => $currentUser->role]`, built right
before calling `handle()` from whatever your authentication layer already
gives you (a session, a JWT claim, a framework's `Auth::user()`, ...).

## Next steps

- [Connections](02-connections.md) if you need MySQL/MariaDB specifics, or
  want to reuse a PDO instance your framework already manages.
- [Resources & security model](03-resources-and-security.md) to understand
  policies in depth — this is the part worth reading carefully before you
  expose anything beyond a toy example.
- [Querying](04-querying.md) for the full filter/pagination syntax.
