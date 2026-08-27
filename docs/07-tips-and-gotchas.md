# Tips & gotchas

A few things that aren't obvious from the API surface alone.

## <a name="emulated-prepares"></a>Why `ATTR_EMULATE_PREPARES` is off

`Connection::make()` sets `PDO::ATTR_EMULATE_PREPARES => false`. With
emulation on, PDO interpolates bound values into the query string itself
(client-side) before sending it — which mostly works, but has historically
been the source of subtle type-coercion and encoding edge cases that real,
driver-level prepared statements don't have. Since this library's whole
premise is "every value is bound, never interpolated," it uses the query
mechanism that actually keeps that promise end-to-end, not just at the PHP
layer. If you're building your own `PDO` instead of using
`Connection::make()`, it's worth setting this yourself.

## `columns()` also governs what comes back, not just what's writable

It's easy to read `columns()` as an insert/update whitelist and forget it
also restricts `select`. If a column isn't listed, `GET` requests can never
return it either — there's no separate read/write whitelist in this version.
If you need a column readable but never writable (e.g. `created_at`), that's
not expressible yet; see the [README's roadmap](../README.md#roadmap).

## A resource with no `allow()` calls is registered but inert

`register()` only makes a resource *reachable* — it doesn't enable anything.
A `Resource` you've built with `columns()` but never called `allow()` on
responds `403` to every request. This is easy to hit by accident if you
build a resource conditionally and forget one branch.

## `select` policies also gate single-row fetches

`GET /{table}/{id}` reuses the `select` policy, not a separate one — there's
no way to allow listing but forbid fetching-by-id, or vice versa. If you only
call `allow(Operation::Select, ...)`, both `GET /{table}` and
`GET /{table}/{id}` use that same policy.

## Insert returns the row insert actually wrote, not what the client sent

`POST` responds with the row as read back from the database (via an internal
`find()`), not an echo of the request body. If a policy forces
`user_id => 42` regardless of what the client sent, the response reflects
`user_id: 42` — this is usually what you want (it shows the client the real
effect of their request), but it does mean an insert costs two queries
internally.

## SQLite specifics

- `Connection::make('sqlite', ':memory:')` gives you a fresh, isolated
  database per `PDO` instance — perfect for tests (see the test suite
  itself, which uses exactly this), useless for anything that needs to
  persist between requests.
- SQLite has no real `VARCHAR`/`INT` type enforcement — a column typed `INT`
  will happily store a string. This is a SQLite characteristic, not
  something pdo-restify adds or removes.

## `in` filters can't contain a literal comma

`status=in.draft,published` splits naively on `,` with no escaping. If a
value you need to filter on can contain a comma, `in` isn't usable for it in
this version — fall back to multiple `eq`/`ne` filters, or wait for a future
version's escaping support.

## The `limit` cap is silent, not an error

Asking for more rows than `Api`'s `$maxLimit` doesn't fail — it's silently
clamped. If pagination looks like it's stuck, check whether you're hitting
the cap rather than assuming a query bug; the response gives no signal that
clamping happened (see the [README's roadmap](../README.md#roadmap) for
planned pagination metadata).
