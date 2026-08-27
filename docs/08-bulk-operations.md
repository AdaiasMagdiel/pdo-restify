# Bulk operations

`POST` and `PATCH`/`PUT` accept either a single row (a JSON object) or
multiple rows at once (a JSON array of objects) — which one you send decides
whether `Api` treats the request as a single write or a bulk one. There's no
separate endpoint or query param to opt in; the shape of the body is the
signal.

## Bulk insert

```
POST /posts
[
  { "title": "First", "body": "...", "user_id": 999 },
  { "title": "Second", "body": "...", "user_id": 999 }
]
```

Each row goes through the exact same validation and policy as a single
`POST` — column whitelist, and the `insert` policy's conditions merged over
(and overriding) whatever the row sent. In this example, both rows still end
up with whatever `user_id` the policy forces, regardless of the `999` sent
by the client.

The response is a JSON array of the created rows, in the same order they
were sent:

```json
[
  { "id": 10, "title": "First", "body": "...", "user_id": 42 },
  { "id": 11, "title": "Second", "body": "...", "user_id": 42 }
]
```

## Bulk update

```
PATCH /posts
[
  { "id": 1, "title": "Updated first" },
  { "id": 2, "title": "Updated second" }
]
```

Each row **must** include the resource's primary key (`id` by default) —
that's how a row is matched to the database record it updates. Every other
key in the row is treated exactly like a single `PATCH /{table}/{id}` body:
filtered through the column whitelist, and combined with the `update`
policy's conditions plus that row's own id.

The response is a JSON array of the updated rows, same order as sent.

## All rows commit together, or none do

Both bulk insert and bulk update run inside a single database transaction.
If any row fails — an unknown column, a missing primary key, a row that
falls outside the policy's conditions — the whole batch is rolled back and
`Api` returns a single error response, exactly like the corresponding
single-row failure (`422`, `404`, etc. — see
[Error handling](06-error-handling.md)). There's no partial success and no
per-row status reporting in this version: it's all-or-nothing.

## What decides "bulk" vs. "single"

A body is treated as bulk when it's a JSON array whose elements are
themselves objects — `[{...}, {...}]`. A single JSON object — `{...}` — is
always a single-row request, even if it only has one key. An **empty**
array (`[]`) is not treated as bulk; it falls through to the single-row path
and fails the same way an empty object would (`422 No data to insert`, or
`400` on a `PATCH`/`PUT` with no id and no rows).

## Not covered in this version

- **Bulk delete.** `DELETE` still only operates on a single `/{table}/{id}`.
- **Partial success / per-row results.** A bulk request either fully
  succeeds or fully fails; there's no way to see which specific rows would
  have succeeded in a batch that ultimately failed.

See the main [README's roadmap](../README.md#roadmap) for what else is
planned.
