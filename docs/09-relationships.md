# Relationships

`select=` can embed related rows alongside a resource's own columns, similar
to PostgREST's `select=*,comments(*)`. A relation is declared once on a
`Resource`; from then on any request that asks for it gets the related rows
loaded and attached, scoped by the related resource's own policy.

## Declaring a relation

Two shapes are supported, matching the two sides of a foreign key:

```php
$posts = (new Resource('posts'))
    ->columns(['id', 'title', 'body', 'user_id'])
    ->allow(Operation::Select)
    ->hasMany('comments', foreignKey: 'post_id');

$comments = (new Resource('comments'))
    ->columns(['id', 'post_id', 'body'])
    ->allow(Operation::Select)
    ->belongsTo('post', foreignKey: 'post_id', table: 'posts');

$api = (new Api($pdo))->register($posts)->register($comments);
```

- **`hasMany($name, foreignKey)`** — this resource has many related rows.
  `$foreignKey` is the column on the *related* table (`comments.post_id`)
  that points back at this resource's primary key.
- **`belongsTo($name, foreignKey)`** — this resource belongs to one related
  row. `$foreignKey` is the column on *this resource's own* table
  (`comments.post_id`) that points at the related resource's primary key.

`$foreignKey` always names an actual FK column, but which table it lives on
depends on the direction — worth re-reading once if it's not clicking, it's
a common point of confusion (Laravel's Eloquent has this exact asymmetry
too, for the same reason).

`$name` is both the key the embedded data appears under in the response and
what you write in `select=`. `$table` is optional and defaults to `$name` —
set it when you want a relation name that differs from the actual table
(e.g. two differently-scoped relations to the same table).

Both resources on either side of a relation must be `register()`'d on the
same `Api` — see [Not covered / gotchas](#not-covered-in-this-version) below
for what happens if you forget.

## Requesting an embed

```
GET /posts?select=id,title,comments(id,body)
```

```json
[
  {
    "id": 1,
    "title": "First post",
    "comments": [
      { "id": 10, "body": "Nice post!" },
      { "id": 11, "body": "Agreed." }
    ]
  }
]
```

A `hasMany` embed is always a JSON array (empty if there are no matches). A
`belongsTo` embed is a single object, or `null` if there's no match:

```
GET /comments/10?select=id,body,post(id,title)
```

```json
{ "id": 10, "body": "Nice post!", "post": { "id": 1, "title": "First post" } }
```

Embeds work on both `GET /{table}` and `GET /{table}/{id}` — not on
`insert`/`update`/`delete` responses.

### Selecting which columns to embed

`relationName(col1,col2)` picks specific columns from the related resource,
validated against *that resource's* whitelist — not the parent's. Empty
parentheses — `relationName()` — embed every column the related resource
allows:

```
GET /posts?select=id,comments()
```

## Security: embeds go through the related resource's own policy

Embedding never bypasses anything. Loading `comments` for a post runs the
comments resource's own `select` policy, with the same `$context` the outer
request got — exactly as if the client had called `GET /comments` directly.
If `comments` has no `select` policy registered, embedding it returns `403`,
same as a direct request would. If its policy scopes rows to, say, only
published comments, the embed only ever contains published comments too.

This means a resource that's never meant to be listed directly can still be
readable *only* through an embed, and vice versa — nothing links a
relation's visibility to whether the related resource is otherwise exposed;
each resource's own `allow(Operation::Select, ...)` is the single source of
truth for whether its rows are readable, embedded or not.

## How it's loaded

Embedding does not run a SQL `JOIN`. For each requested relation, pdo-restify
gathers the relevant keys from the already-fetched parent rows (the parent's
primary key for a `hasMany`, or the parent's foreign key column for a
`belongsTo`) and runs one additional query with an `IN (...)` filter against
the related table — the same `Filters`/`QueryBuilder`/policy pipeline every
other request goes through, just invoked once more per relation, not once
per row. This keeps the security model (whitelist + policy, always) and the
query-building code identical for embedded and top-level requests, at the
cost of N+1 queries (one per relation, not per row) rather than a single
join.

## Not covered in this version

- **Only one level deep.** `comments(replies(*))` isn't supported —
  `comments(*)` is as far as it goes.
- **No filter/order/pagination on the embedded set.** All matching related
  rows come back, in whatever order the database returns them; there's no
  `comments(id,body)?order=...` equivalent yet.
- **Many-to-many (pivot tables) and `hasOne`.** Only `hasMany` and
  `belongsTo` exist in this version.

See the main [README's roadmap](../README.md#roadmap) for what else is
planned.

## Gotchas

- **A relation pointing at an unregistered resource is a setup bug, not a
  client error.** If `hasMany('comments', ...)` is declared but the
  `comments` `Resource` was never `register()`'d on the same `Api`,
  requesting that embed throws a plain `\LogicException` — uncaught by
  `Api::handle()`, not turned into a `4xx` response — because it's something
  only a code change can fix, not a different request. See
  [Error handling](06-error-handling.md).
- **The join column doesn't need to be in the related resource's `columns()`
  whitelist**, but it's usually there anyway (it's a real column most APIs
  expose). If it isn't whitelisted, pdo-restify still uses it internally to
  match rows — validated as a safe identifier when the relation was declared
  — and strips it from the response unless the caller explicitly asked for
  it via `relationName(joinColumn,...)`.
