# Connections

pdo-restify doesn't care where its `PDO` instance came from. `Connection` is a
convenience for building one; it's entirely optional.

## Using `Connection::make()`

```php
use AdaiasMagdiel\PdoRestify\Connection;

// SQLite — file-backed
$pdo = Connection::make('sqlite', __DIR__ . '/database.sqlite');

// SQLite — in-memory (great for tests, gone when the connection closes)
$pdo = Connection::make('sqlite', ':memory:');

// MySQL / MariaDB
$pdo = Connection::make(
    driver: 'mysql', // or 'mariadb' — both build the same mysql: DSN
    database: 'my_app',
    host: '127.0.0.1',
    port: 3306,
    username: 'app',
    password: 'secret',
);
```

`make()` sets three PDO attributes for you, always:

| Attribute | Value | Why |
|---|---|---|
| `ATTR_ERRMODE` | `ERRMODE_EXCEPTION` | Failed queries throw instead of failing silently or requiring manual error checks. |
| `ATTR_DEFAULT_FETCH_MODE` | `FETCH_ASSOC` | `Api` expects associative arrays back from `fetch()`/`fetchAll()`. |
| `ATTR_EMULATE_PREPARES` | `false` | Real, driver-level prepared statements — see [Tips & gotchas](07-tips-and-gotchas.md#emulated-prepares). |

Pass a 7th `$options` array to add or override PDO driver options; it's
merged *under* the defaults above, so you can still override them if you
have a reason to.

## Bringing your own PDO

Both `Resource`/`Api` only need a `PDO` — nothing checks that it came from
`Connection::make()`. If your framework already manages a connection (a
Laravel `DB` facade's underlying PDO, a Doctrine connection's
`->getWrappedConnection()->getNativeConnection()`, a connection pool, ...),
just pass it straight to `new Api($pdo)`:

```php
$api = new Api($existingPdo);
```

Two things pdo-restify assumes about whatever PDO you give it:

- `ATTR_ERRMODE` is `ERRMODE_EXCEPTION` (or you're prepared to handle PDO
  returning `false` on failure yourself — the library's own error handling
  assumes exceptions).
- The default fetch mode returns associative arrays, or you accept that
  `list()`/`find()` results won't look like plain `column => value` rows.

## Supported drivers

Only `mysql`, `mariadb` and `sqlite` are supported in this first version —
see the main [README](../README.md#roadmap) for what's planned next.
MariaDB uses the exact same `mysql:` DSN prefix as MySQL (that's how PDO's
own `pdo_mysql` driver talks to both), so `driver: 'mariadb'` and
`driver: 'mysql'` behave identically in `Connection::make()`; the separate
name exists so your code reads honestly when you're actually running
MariaDB.
