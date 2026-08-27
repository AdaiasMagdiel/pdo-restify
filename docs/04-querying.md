# Querying

Everything here applies to `GET /{table}` (`Api`'s `list` action). A single
`GET /{table}/{id}` fetch ignores all of this — it only ever applies the
resource's policy conditions plus the id.

## Filters

Any query string key that isn't `select`, `order`, `limit` or `offset` is
treated as a filter on that column, in `operator.value` form:

```
GET /posts?title=like.*hello*&views=gte.100
```

| Operator | SQL | Example |
|---|---|---|
| `eq` | `=` | `status=eq.published` |
| `ne` | `!=` | `status=ne.draft` |
| `gt` | `>` | `views=gt.100` |
| `gte` | `>=` | `views=gte.100` |
| `lt` | `<` | `price=lt.20` |
| `lte` | `<=` | `price=lte.20` |
| `like` | `LIKE` | `title=like.*hello*` (`*` becomes SQL's `%`) |
| `in` | `IN (...)` | `status=in.draft,published,archived` |

A few rules that are easy to miss:

- The column **must** be in the resource's `columns()` whitelist, or the
  request fails with `422` — this is enforced independently of, and in
  addition to, whatever a policy scopes.
- The value **must** contain a `.` (i.e. `operator.value`). `title=hello`
  with no operator is rejected — there's no implicit `eq`, on purpose:
  guessing the operator would make the query string ambiguous to read.
  Write `title=eq.hello`.
- `like`'s wildcard is `*`, not SQL's native `%`. `str_replace('*', '%', ...)`
  runs on the raw value, so a literal `%` in a `like` filter is passed
  through untouched (and matched literally by `LIKE`) — only `*` triggers
  the wildcard substitution.
- `in` splits on `,` with no escaping — a value containing a literal comma
  can't be expressed with this operator in this version.
- Filters are always combined with `AND`, both with each other and with the
  resource's policy conditions. There's no `OR` and no grouping in this
  version — see the main [README](../README.md#roadmap).

## Selecting columns

```
GET /posts?select=id,title
```

Restricts which columns come back, as a comma-separated list. Every column
listed must be in the resource's whitelist (same `422` as filters). Omit
`select` entirely and every whitelisted column is returned.

## Ordering

```
GET /posts?order=created_at.desc
```

Format is `column.direction`; `direction` defaults to `asc` if omitted
(`order=created_at` is equivalent to `order=created_at.asc`). Only one order
column is supported per request in this version. The column must be in the
whitelist.

## Pagination

```
GET /posts?limit=20&offset=40
```

- `limit` defaults to 50 if omitted, and is always capped at the `Api`
  instance's `$maxLimit` constructor argument (100 by default) — a client
  asking for `limit=100000` silently gets `$maxLimit` rows instead, it never
  errors.
- `offset` defaults to 0, and negative values are clamped to 0.
- There's no total-count header or cursor-based pagination in this version
  — see the [README's roadmap](../README.md#roadmap).

## Putting it together

```
GET /posts?status=eq.published&views=gte.100&select=id,title,views&order=views.desc&limit=10
```

Reads as: published posts with at least 100 views, returning only
`id`/`title`/`views`, sorted by views descending, first 10 — all `AND`ed
with whatever the resource's `select` policy adds on top.
