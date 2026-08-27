# Error handling

`Api::handle()` never throws. Every exception raised while processing a
request — by pdo-restify itself, or by a policy closure — is caught inside
`handle()` and turned into an error `Response` instead. Anything that
escapes that (a raw `PDOException` from a database-level failure, for
instance) is a bug or an infrastructure problem, not a normal error path;
it's up to your application whether to let it propagate or wrap it.

## Response shape

Every error response has the same body shape:

```json
{ "error": "Human-readable message" }
```

`$response->status` carries the HTTP status code. There's no machine-readable
error code or field-level validation detail in this version — see the main
[README's roadmap](../README.md#roadmap).

## Status codes and what triggers them

| Status | Exception | When |
|---|---|---|
| `400` | `BadRequestException` | Unsupported HTTP method, or a `PATCH`/`PUT`/`DELETE` with no id in the path. |
| `403` | `ForbiddenException` | The operation (`select`/`insert`/`update`/`delete`) has no policy registered on the resource, or a policy closure throws it itself. |
| `404` | `NotFoundException` | The resource/table isn't registered, or no row matches the requested id once the policy's conditions are applied. |
| `422` | `ValidationException` | A filter/select/order column isn't in the resource's whitelist, a filter is missing its `operator.` prefix or uses an unknown operator, or an insert/update body is empty or references an unknown column. |

## 404 vs. 403 on row access

Fetching, updating or deleting a row that exists but is outside the caller's
policy conditions returns `404`, not `403`. This is deliberate: returning
`403` would confirm the row exists, just that this caller can't touch it —
information a scoped API generally shouldn't leak. `404` makes "doesn't
exist" and "exists, but not yours" indistinguishable from the outside, which
is the safer default for row-level access. `403` is reserved for the
resource-level question — "is this operation even enabled here" — which
isn't caller- or row-specific, so there's nothing to hide by returning it.

## Throwing from inside a policy

Policies are plain closures, so throwing `ForbiddenException` from one — or
any other `ApiException` subclass — is caught by `handle()` exactly like an
exception pdo-restify raises internally:

```php
use AdaiasMagdiel\PdoRestify\Exceptions\ForbiddenException;

$adminOnly = function (array $context): array {
    if (($context['role'] ?? null) !== 'admin') {
        throw new ForbiddenException('Admins only');
    }

    return [];
};
```

See [Resources & security model](03-resources-and-security.md#role-based-example)
for a fuller example.
