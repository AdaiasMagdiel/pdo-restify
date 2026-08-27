# Documentation

- [Getting started](01-getting-started.md) — install, connect, expose your first table, make your first request.
- [Connections](02-connections.md) — `Connection::make()` vs. bringing your own PDO, driver notes for MySQL/MariaDB/SQLite.
- [Resources & security model](03-resources-and-security.md) — column whitelists, `allow()`, policies as an RLS substitute, and when it's fine to skip one.
- [Querying](04-querying.md) — filter operators, `select`, `order`, pagination, and how each one maps to SQL.
- [Integrating with a framework](05-integrating-with-frameworks.md) — bridging `Api::handle()` into Slim, Laravel, CakePHP, Erlenmeyer or a plain script.
- [Error handling](06-error-handling.md) — exceptions, their HTTP status codes, and the response shape.
- [Tips & gotchas](07-tips-and-gotchas.md) — things that will bite you if you don't know them going in.
- [API reference](api-reference.md) — every public class and method, in one page.

If something here is unclear or missing, that's a documentation bug — please open an issue.
