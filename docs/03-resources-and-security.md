# Resources & security model

This is the part worth reading slowly. Everything else in the library is
plumbing around the decisions described here.

## Resources are opt-in, table by table

A `Resource` is the only thing that makes a table reachable at all:

```php
$posts = (new Resource('posts'))
    ->columns(['id', 'title', 'body', 'user_id']);
```

Two things happen here:

- The table name (`posts`) is validated against `^[a-zA-Z_][a-zA-Z0-9_]*$` —
  it can never carry anything but an identifier, so it can never be used to
  smuggle SQL.
- `columns()` sets the **only** columns this resource will ever read or
  write. Anything not in that list — as a filter, a `select=` param, or a
  key in an insert/update body — is rejected with a `422`. There is no way
  to opt out of this whitelist; if a column isn't listed, it doesn't exist
  as far as the resource is concerned.

A table you never wrap in a `Resource`, or a `Resource` you never `register()`
on an `Api`, is completely unreachable through that `Api` — this is the
deny-by-default posture at the table level, and it's not configurable. It's
also not enough on its own: see the next section.

## Operations are opt-in too

Registering a resource exposes nothing by itself. Each `Operation` case —
`Select`, `Insert`, `Update`, `Delete` — must be turned on explicitly:

```php
$posts->allow(Operation::Select, $somePolicy);
```

Calling `handle()` for an operation that was never `allow()`'d returns
`403 Forbidden`. A `Resource` with `columns()` set but no `allow()` calls at
all is registered, but entirely closed — every request against it fails.

## Policies: real SQL, not a condition DSL

PostgreSQL can enforce Row-Level Security at the database layer, independent
of the application querying it. PDO has nothing like that, so pdo-restify
gives you the same expressive power at the application layer instead:
`allow()`'s second argument is a **policy** — a closure that receives the
request context and returns a `RawCondition`, a raw SQL boolean expression
plus its bound parameters, that is always enforced on that operation no
matter what the client sent.

```php
$scopedToCurrentUser = fn (array $context): RawCondition =>
    new RawCondition('user_id = :uid', [':uid' => $context['user_id']]);

$posts->allow(Operation::Select, $scopedToCurrentUser);
```

Because it's real SQL, a policy isn't limited to a single equality — `OR`,
`IN (...)`, comparisons against other columns, function calls, anything your
database's `WHERE` clause accepts is fair game:

```php
fn (array $context): RawCondition => new RawCondition(
    'user_id = :uid OR :role = \'admin\'',
    [':uid' => $context['user_id'], ':role' => $context['role'] ?? null],
);
```

`RawCondition::$sql` is merged into the query verbatim — pdo-restify does not
parse or validate it, the same way it doesn't validate the table/column names
you pass it. This is deliberate: a policy is application code you write, not
client input. See [Identifiers are always validated, values are always
bound](#identifiers-are-always-validated-values-are-always-bound) below for
exactly where the trust boundary sits.

What this buys you, concretely:

- **On `select`**, the condition becomes an extra, parenthesized `AND` clause
  on every `SELECT` — a caller can never list or fetch a row outside what the
  policy allows, regardless of what filters they pass in the query string.
- **On `update`/`delete`**, the condition is combined with the row's id in
  the `WHERE` clause (Postgres calls this `USING`) — a caller can send a
  valid id that isn't theirs, and the operation still fails as if the row
  didn't exist (`404`, not `403` — see [Error handling](06-error-handling.md)
  for why that's the right status to leak).
- **On `insert`, and again on `update`**, the same condition is also a
  **`WITH CHECK`**: after the write runs, pdo-restify re-evaluates it against
  the resulting row, and rolls the write back with a `403` if it no longer
  holds. A client that sends `{"user_id": 999}` on an insert scoped to
  `user_id = :uid` (their own id) gets rejected outright, rather than having
  `user_id` silently overwritten — the row must be correct as submitted, the
  same way Postgres's own `WITH CHECK` works. This is the one that matters:
  without it, `insert`/`update` policies would be decorative.

A policy always receives the `$context` array passed as `Api::handle()`'s
second argument, and nothing else — it has no access to the request path,
query string or body. That's intentional: a policy answers "what is this
caller allowed to touch", a question that should only depend on who they
are, never on what they're asking for.

### Public read, admin-only write

Probably the single most common shape: anyone can read, only admins can
change anything. It's just the "no policy at all" and "role-based" pieces
above, combined on one resource:

```php
use AdaiasMagdiel\PdoRestify\Exceptions\ForbiddenException;

$posts = (new Resource('posts'))
    ->columns(['id', 'title', 'body', 'user_id']);

$adminOnly = function (array $context): ?RawCondition {
    if (($context['role'] ?? null) !== 'admin') {
        throw new ForbiddenException('Admins only');
    }

    return null; // an admin isn't scoped to their own rows either
};

$posts
    ->allow(Operation::Select)                // no closure — public, unrestricted read
    ->allow(Operation::Insert, $adminOnly)
    ->allow(Operation::Update, $adminOnly)
    ->allow(Operation::Delete, $adminOnly);
```

`GET /posts` and `GET /posts/{id}` work for anyone, with no `$context`
required at all (you can call `handle($request, [])`). `POST`/`PATCH`/`DELETE`
throw `403` for anyone whose context doesn't say `role: admin` — which your
application decides and builds before calling `handle()`, typically by
reading it off the authenticated session/token:

```php
$context = $currentUser !== null ? ['role' => $currentUser->role] : [];

$response = $api->handle($request, $context);
```

Nothing here is a new mechanism — it's the exact same `allow()`/policy API
used everywhere else in this guide, just applied per operation on the same
resource rather than uniformly across all four.

### Multi-tenant example

```php
$scopedToTenant = fn (array $context): RawCondition =>
    new RawCondition('tenant_id = :tid', [':tid' => $context['tenant_id']]);

$orders->allow(Operation::Select, $scopedToTenant);
$orders->allow(Operation::Insert, $scopedToTenant);
```

### Role-based example

A policy can return a different condition — or refuse the request outright —
based on anything in the context:

```php
$scopedByRole = function (array $context): ?RawCondition {
    if (($context['role'] ?? null) === 'admin') {
        return null; // no restriction — admins see every row
    }

    return new RawCondition('owner_id = :uid', [':uid' => $context['user_id']]);
};

$documents->allow(Operation::Select, $scopedByRole);
```

Since a policy is a plain closure, it can throw too — throwing
`ForbiddenException` from inside one rejects the request with `403` before
any query runs:

```php
use AdaiasMagdiel\PdoRestify\Exceptions\ForbiddenException;

$adminOnly = function (array $context): ?RawCondition {
    if (($context['role'] ?? null) !== 'admin') {
        throw new ForbiddenException('Admins only');
    }

    return null;
};

$auditLog->allow(Operation::Select, $adminOnly);
```

## Skipping a policy on purpose

A policy is **optional**, not implied. Call `allow()` with no second
argument — or have your closure return `null` — and that operation is
enabled with zero row-level restriction:

```php
$posts->allow(Operation::Select); // every caller sees every row, for this operation
```

pdo-restify won't force a scoping scheme on you. Plenty of real APIs don't
need one — a public read-only dataset, an internal admin tool that already
sits behind its own auth middleware, a single-tenant app where "every row"
*is* the correct answer. Writing a no-op policy (`fn () => null`, which is
exactly what an omitted one does internally) for those cases is pure
ceremony, and forcing it wouldn't make the API any safer, just noisier.

The trade-off is real, though: skipping a policy on `select` means literally
every caller who can reach the endpoint sees every row that operation
touches. Make that call deliberately, per operation, not by default — and
remember it applies per operation, not per resource, so it's entirely normal
to have `select` wide open on a resource while `insert`/`update`/`delete`
stay scoped.

## Identifiers are always validated, values are always bound

Two independent mechanisms keep every generated query safe:

- **Identifiers** (table names, column names, order/filter columns) only
  ever reach the SQL string after passing `Resource::assertIdentifier()` — a
  single, strict regex (`^[a-zA-Z_][a-zA-Z0-9_]*$`). `QueryBuilder` itself
  re-checks every identifier it's given, unconditionally, rather than
  trusting its caller to have done so — there is no code path that puts an
  unvalidated identifier into a query from request-derived input.
- **Client-supplied values** (filter values, insert/update data) are never
  interpolated. They're always passed through as bound parameters to
  `PDOStatement::execute()`. See `QueryBuilder` in the [API
  reference](api-reference.md) if you want to see exactly how.

A `RawCondition`'s `$sql` is the one deliberate exception: it's merged into
the query verbatim, unvalidated, because it *is* SQL a policy — your own
application code — chose to write, not client input being smuggled through.
The values a `RawCondition` references are still always bound through its
`$params`, never interpolated, even though the surrounding expression isn't
checked. In other words: pdo-restify trusts a policy's *code* the same way it
trusts the table/column names you pass it, but never trusts a *value*,
policy-supplied or not, enough to concatenate it into SQL.
