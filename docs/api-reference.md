# API reference

All classes live under the `AdaiasMagdiel\PdoRestify` namespace unless noted
otherwise. This page documents the public surface; see the source for
further detail (every method also carries a PHPDoc block).

- [`Connection`](#connection)
- [`Resource`](#resource)
- [`Operation`](#operation)
- [`Api`](#api)
- [`Filters`](#filters)
- [`QueryBuilder`](#querybuilder)
- [`Http\Request`](#httprequest)
- [`Http\Response`](#httpresponse)
- [Exceptions](#exceptions)

---

## `Connection`

Static factory for a pre-configured `PDO` instance. See
[Connections](02-connections.md) for the full guide.

### `Connection::make()`

```php
public static function make(
    string $driver,
    string $database,
    ?string $host = null,
    ?int $port = null,
    ?string $username = null,
    ?string $password = null,
    array $options = [],
): PDO
```

| Param | Meaning |
|---|---|
| `$driver` | One of `'mysql'`, `'mariadb'`, `'sqlite'`. |
| `$database` | Database name (mysql/mariadb) or file path / `:memory:` (sqlite). |
| `$host` | mysql/mariadb only. Defaults to `127.0.0.1`. |
| `$port` | mysql/mariadb only. Defaults to `3306`. |
| `$username`, `$password` | Passed straight to `PDO`'s constructor. |
| `$options` | Extra PDO driver options, merged under the library's defaults. |

Throws `\InvalidArgumentException` if `$driver` isn't one of the three
supported values.

---

## `Resource`

Wraps one database table and its access rules. See
[Resources & security model](03-resources-and-security.md) for the full
guide.

### Constructor

```php
public function __construct(
    public readonly string $table,
    public readonly string $primaryKey = 'id',
)
```

Validates `$table` and `$primaryKey` as SQL identifiers
(`^[a-zA-Z_][a-zA-Z0-9_]*$`). Throws `\InvalidArgumentException` otherwise.

### `columns()`

```php
public function columns(array $columns): static
```

Sets the column whitelist. `$columns` is a list of column name strings, each
validated as an identifier. Returns `$this` for chaining.

### `allowedColumns()`

```php
public function allowedColumns(): array
```

Returns the whitelist set by `columns()` (empty array if never called).

### `allow()`

```php
public function allow(Operation $operation, ?\Closure $policy = null): static
```

Enables `$operation`, optionally scoped by `$policy`. `$policy` has the
signature `function (array $context): array` and returns conditions always
enforced for that operation. Passing no `$policy` enables the operation with
no scoping at all. Returns `$this` for chaining.

### `policyFor()`

```php
public function policyFor(Operation $operation): \Closure
```

Returns the closure registered via `allow()` for `$operation`. Throws
`Exceptions\ForbiddenException` if that operation was never enabled. Mostly
used internally by `Api`; rarely called directly.

### `assertIdentifier()`

```php
public static function assertIdentifier(string $name): void
```

The identifier-validation primitive used throughout the library (table
names, column names). Throws `\InvalidArgumentException` if `$name` isn't a
valid, unquoted SQL identifier. Exposed publicly in case you need the same
guarantee elsewhere in your app.

---

## `Operation`

```php
enum Operation: string
{
    case Select = 'select';
    case Insert = 'insert';
    case Update = 'update';
    case Delete = 'delete';
}
```

A string-backed enum of the four CRUD operations a `Resource` can expose.
Used as the first argument to `Resource::allow()`/`policyFor()`; passing
anything else there is a `TypeError`, not a runtime validation failure —
invalid operations are caught by the language itself, not by pdo-restify's
own code.

---

## `Api`

The library's entry point. See [Getting started](01-getting-started.md).

### Constructor

```php
public function __construct(
    private readonly PDO $pdo,
    private readonly int $maxLimit = 100,
)
```

`$maxLimit` is the hard ceiling applied to the `limit` query param on `GET`
list requests (see [Querying](04-querying.md#pagination)).

### `register()`

```php
public function register(Resource $resource): static
```

Makes `$resource` reachable through `handle()`, keyed by its table name.
Registering a second resource with the same table name replaces the first.
Returns `$this` for chaining.

### `handle()`

```php
public function handle(Http\Request $request, array $context = []): Http\Response
```

Routes `$request` to the matching resource and CRUD action based on
`$request->method` and `$request->path` (`{table}` or `{table}/{id}`).
`$context` is passed through, unmodified, to whichever policy closure ends
up running. Never throws — every internal exception is caught and turned
into an error `Response` (see [Error handling](06-error-handling.md)).

| Method | Path | Action |
|---|---|---|
| `GET` | `/{table}` | List rows, filters/select/order/pagination apply. |
| `GET` | `/{table}/{id}` | Fetch a single row. |
| `POST` | `/{table}` | Insert a row, or bulk-insert if the body is a list of objects. |
| `PATCH` or `PUT` | `/{table}/{id}` | Update a row. |
| `PATCH` or `PUT` | `/{table}` | Bulk-update rows; each body object must include the primary key. |
| `DELETE` | `/{table}/{id}` | Delete a row. |

See [Bulk operations](08-bulk-operations.md) for the bulk insert/update
request and response shape, and the all-or-nothing transaction semantics.

---

## `Filters`

Query-string parsing, used internally by `Api::handle()`. See
[Querying](04-querying.md) for the syntax these methods implement.

### `Filters::parse()`

```php
public static function parse(array $query, array $allowedColumns): array
```

Extracts `column=operator.value` entries from `$query`, skipping reserved
keys (`select`, `order`, `limit`, `offset`). Returns a list of
`[column, operator, rawValue]` tuples. Throws
`Exceptions\ValidationException` on a non-whitelisted column, a missing
`operator.` prefix, or an unrecognized operator.

### `Filters::order()`

```php
public static function order(?string $order, array $allowedColumns): ?array
```

Parses `column.direction` (direction defaults to `asc`). Returns `null` if
`$order` is `null`/empty, otherwise `[column, direction]`. Throws
`Exceptions\ValidationException` on a non-whitelisted column or an invalid
direction.

### `Filters::select()`

```php
public static function select(?string $select, array $allowedColumns): array
```

Resolves a comma-separated `select=` value against `$allowedColumns`,
defaulting to the full whitelist when `$select` is `null`/empty. Throws
`Exceptions\ValidationException` on a non-whitelisted column.

---

## `QueryBuilder`

Turns validated identifiers and filter tuples into `[sql, params]` pairs
ready for `PDO::prepare()`/`PDOStatement::execute()`. Used internally by
`Api`; documented here for completeness, not something you're expected to
call directly.

```php
public static function select(string $table, array $columns, array $filters, array $conditions, ?array $order, int $limit, int $offset): array
public static function insert(string $table, array $data): array
public static function update(string $table, array $data, array $conditions): array
public static function delete(string $table, array $conditions): array
```

Every value ends up bound as a parameter; the only strings interpolated
into the SQL are identifiers the caller has already validated (see
[Resources & security model](03-resources-and-security.md#identifiers-are-always-validated-values-are-always-bound)).

---

## `Http\Request`

Framework-agnostic request DTO consumed by `Api::handle()`.

```php
final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
    )
}
```

| Property | Meaning |
|---|---|
| `$method` | HTTP verb: `GET`, `POST`, `PATCH`, `PUT`, `DELETE`. |
| `$path` | `{table}` or `{table}/{id}`, with any framework mount prefix already stripped. |
| `$query` | Raw query string params, unparsed filter syntax. |
| `$body` | Request body, already decoded into an associative array (e.g. via `json_decode(..., true)`). |

---

## `Http\Response`

Framework-agnostic response DTO returned by `Api::handle()`.

```php
final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly mixed $body,
        public readonly array $headers = ['Content-Type' => 'application/json'],
    )

    public static function json(mixed $body, int $status = 200): self
}
```

`$body` is plain PHP data (array, scalar, or `null` for a `204`) — encoding
it (typically `json_encode`) is the caller's job. `Response::json()` is a
shorthand constructor for the common case.

---

## Exceptions

All under `AdaiasMagdiel\PdoRestify\Exceptions`. Every subclass of
`ApiException` is caught by `Api::handle()` and turned into an error
response using `status()` as the HTTP status code (see
[Error handling](06-error-handling.md)).

| Class | `status()` | Meaning |
|---|---|---|
| `ApiException` | *(abstract)* | Base class; defines the `status(): int` contract every subclass implements. |
| `BadRequestException` | 400 | Malformed request at the routing level. |
| `ForbiddenException` | 403 | Operation has no policy registered, or a policy threw it. |
| `NotFoundException` | 404 | Unregistered resource, or no row matches id + policy conditions. |
| `ValidationException` | 422 | Unknown column, malformed filter, or empty write payload. |

Any of these can be thrown from application code too (e.g. from inside a
policy closure) and will be handled the same way `Api` handles its own.
