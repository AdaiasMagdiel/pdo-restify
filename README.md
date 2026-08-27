# pdo-restify

A small, framework-agnostic REST API layer on top of PDO. It exposes whitelisted
database tables as CRUD endpoints — inspired by [PostgREST](https://postgrest.org/)
— but works with plain PDO, so it runs on MySQL, MariaDB and SQLite, and can be
plugged into any PHP application: Slim, Laravel, CakePHP, [Erlenmeyer](https://github.com/adaiasmagdiel/erlenmeyer),
or a plain script.

> Early, minimal first version. The scope is intentionally small — see
> [Roadmap](#roadmap).

## Why

PostgreSQL has Row-Level Security to scope what each request can see or change.
PDO has nothing like that built in, and pdo-restify doesn't try to fake it either
— it gives you the pieces and leaves the decision to you:

- **Deny by default, at the table level.** A table is only reachable if you
  explicitly register it as a `Resource` with an explicit column whitelist, and
  each operation (`select`, `insert`, `update`, `delete`) must be explicitly
  enabled with `allow()`. Anything you don't register stays unreachable.
- **Row-level scoping is optional, not imposed.** `allow()` takes an optional
  policy closure that returns conditions always enforced for that operation
  (and those values override whatever the client sent) — this is how you
  emulate RLS on top of PDO, scoping rows to e.g. the authenticated user.
  Call `allow('select')` with no closure and that operation is wide open, no
  scoping at all. pdo-restify won't force a security architecture on you: some
  APIs genuinely don't need per-row scoping (public read-only data, an admin
  tool behind its own auth layer, a single-tenant app), and bolting on a
  no-op policy for those cases would just be ceremony. The trade-off is
  yours to make, deliberately — just know that skipping a policy means every
  caller sees every row for that operation.
- **Every query is parameterized.** Table, column and operator names are
  validated against a whitelist before they ever reach a SQL string; values are
  always bound as parameters, never interpolated.

## Install

```bash
composer require adaiasmagdiel/pdo-restify
```

## Quick start

```php
use AdaiasMagdiel\PdoRestify\Api;
use AdaiasMagdiel\PdoRestify\Connection;
use AdaiasMagdiel\PdoRestify\Http\Request;
use AdaiasMagdiel\PdoRestify\Resource;

// Either let pdo-restify build the PDO instance for you...
$pdo = Connection::make('sqlite', __DIR__ . '/database.sqlite');
// ...or bring your own, already-configured PDO instance. pdo-restify doesn't
// care where it came from.

$posts = (new Resource('posts'))
    ->columns(['id', 'title', 'body', 'user_id']);

// The context comes from your app (e.g. the authenticated user). It's up to
// you to build it and pass it into handle() below.
$scopedToCurrentUser = fn (array $context): array => ['user_id' => $context['user_id']];

$posts
    ->allow('select', $scopedToCurrentUser)
    ->allow('insert', $scopedToCurrentUser)
    ->allow('update', $scopedToCurrentUser)
    ->allow('delete', $scopedToCurrentUser);

$api = (new Api($pdo))->register($posts);

// No scoping needed for this operation? Skip the closure entirely and the
// resource stays wide open for it:
// $posts->allow('select');

$request = new Request(
    method: 'GET',
    path: '/posts',
    query: ['title' => 'like.*hello*', 'order' => 'id.desc', 'limit' => '10'],
);

$response = $api->handle($request, context: ['user_id' => 42]);

// $response->status  -> 200
// $response->body    -> the matching rows, scoped to user_id = 42
```

`Api::handle()` takes and returns plain data — it does no I/O by itself. Your
app (or a thin bridge, see [`examples/erlenmeyer-bridge.php`](examples/erlenmeyer-bridge.php))
is responsible for turning the real HTTP request into a `Request` and writing
the resulting `Response` back out. This is what makes pdo-restify pluggable
into any router or framework.

## Query string

| Param             | Example                     | Meaning                                   |
|--------------------|------------------------------|--------------------------------------------|
| `<column>`          | `age=gt.18`                  | Filter, `operator.value`                    |
| `select`            | `select=id,title`             | Which whitelisted columns to return         |
| `order`             | `order=created_at.desc`       | Sort column and direction                   |
| `limit` / `offset`  | `limit=20&offset=40`          | Pagination, `limit` is capped server-side   |

Supported filter operators: `eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `like`
(`*` is the wildcard), `in` (comma-separated values).

## Routes

`Api::handle()` dispatches based on `Request::$path` and `Request::$method`:

| Method  | Path            | Action                        |
|---------|-----------------|--------------------------------|
| GET     | `/{table}`       | List rows (filters apply)      |
| GET     | `/{table}/{id}`  | Fetch a single row              |
| POST    | `/{table}`       | Insert a row                    |
| PATCH   | `/{table}/{id}`  | Update a row                    |
| DELETE  | `/{table}/{id}`  | Delete a row                    |

## Testing

The suite runs on [Pest](https://pestphp.com/) against an in-memory SQLite
database:

```bash
composer test
```

## Roadmap

- Relationships / embedded resources (joins)
- Bulk insert/update
- Pluggable authentication helpers
- RPC-style calls to stored procedures/functions

## License

[LGPL-3.0-or-later](LICENSE).
