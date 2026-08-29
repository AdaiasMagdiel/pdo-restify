# Bulk operations

`POST`, `PATCH`/`PUT`, and `DELETE` all accept a bulk form when there's no
id in the path — a JSON array in the body instead of a single JSON object
(or, for `DELETE`, instead of nothing at all). There's no separate endpoint
or query param to opt in; the shape of the body is the signal.

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

## Bulk delete

```
DELETE /posts
[1, 2, 3]
```

Unlike insert/update, a bulk delete body is a flat array of primary key
values — there's no other data to send for a delete, so there's no reason
to wrap each one in an object. Each id is deleted exactly like a single
`DELETE /{table}/{id}`: scoped by the `delete` policy's conditions, `404` if
an id doesn't exist or falls outside them.

The response is a `204 No Content` with an empty body, same as a single
delete — there's nothing per-row to report back for a delete.

## Filtered update and delete

`/{table}/{id}` isn't a distinct code path — it's sugar for a `{pk}=eq.{id}`
filter internally. `PATCH`/`PUT` and `DELETE` accept the same
`column=operator.value` filters as `GET /{table}` (see
[Querying](04-querying.md#filters)) directly in the query string too, when
there's no id in the path and the body isn't a bulk array/id-list:

```
PATCH /posts?status=eq.draft
{ "status": "archived" }
```

```
DELETE /posts?views=lt.10
```

Every row matching **both** the filters and the resource's `update`/`delete`
policy conditions is affected — the two are always `AND`ed together, so a
filter can never reach a row the policy wouldn't otherwise scope. Unlike a
single or by-id bulk update/delete, matching zero rows is **not** an error:
a filtered `PATCH` returns `200` with an empty array, and a filtered
`DELETE` returns `204`, the same way a `GET` list with no matches returns an
empty array instead of `404`.

A filter query string and a bulk (array) body can't be combined in the same
request — `400 Bad Request` if both are present. `order`, `limit`, and
`offset` are accepted in the query string but ignored for these two verbs;
only the filter keys apply.

## All rows commit together, or none do

Bulk insert, update, and delete all run inside a single database
transaction. If any row fails — an unknown column, a missing primary key, an
id that isn't a scalar, a row that falls outside the policy's conditions —
the whole batch is rolled back and `Api` returns a single error response,
exactly like the corresponding single-row failure (`422`, `404`, etc. — see
[Error handling](06-error-handling.md)). There's no partial success and no
per-row status reporting in this version: it's all-or-nothing.

## What decides "bulk" vs. "single"

For `POST`/`PATCH`/`PUT`, a body is treated as bulk when it's a JSON array
whose elements are themselves objects — `[{...}, {...}]`. A single JSON
object — `{...}` — is always a single-row request, even if it only has one
key. For `DELETE`, any non-empty JSON array with no id in the path is a bulk
delete — `[1, 2, 3]`, not `[{...}]`.

An **empty** array (`[]`) is never treated as bulk for any of the three; it
falls through to the single-row/single-id path and fails the same way an
empty object (or a path with no id) would — `422 No data to insert` for
`POST`, or `400` for `PATCH`/`PUT`/`DELETE` with no id and no valid bulk
body.

## Not covered in this version

- **Partial success / per-row results.** A bulk request either fully
  succeeds or fully fails; there's no way to see which specific rows would
  have succeeded in a batch that ultimately failed.

See the main [README's roadmap](../README.md#roadmap) for what else is
planned.
