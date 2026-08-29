<?php

declare(strict_types=1);

namespace AdaiasMagdiel\PdoRestify;

use AdaiasMagdiel\PdoRestify\Exceptions\ApiException;
use AdaiasMagdiel\PdoRestify\Exceptions\BadRequestException;
use AdaiasMagdiel\PdoRestify\Exceptions\NotFoundException;
use AdaiasMagdiel\PdoRestify\Exceptions\ValidationException;
use AdaiasMagdiel\PdoRestify\Http\Request;
use AdaiasMagdiel\PdoRestify\Http\Response;
use PDO;

/**
 * Dispatches REST requests against registered {@see Resource} definitions.
 *
 * This is the library's single entry point: it does no I/O of its own and
 * only translates a {@see Request} into a {@see Response}. Wiring an actual
 * HTTP request/response cycle into it is the caller's responsibility, which
 * is what keeps pdo-restify usable from any router or framework.
 */
final class Api
{
    /**
     * Rows returned by GET when the client does not send a `limit` param.
     */
    private const DEFAULT_LIMIT = 50;

    /** @var array<string, Resource> Resources registered via {@see self::register()}, keyed by table name. */
    private array $resources = [];

    /**
     * @param PDO $pdo Connection used to run every query. See {@see Connection::make()}
     *                  to build one, or pass an already-configured PDO instance.
     * @param int $maxLimit Hard ceiling applied to the `limit` query param on GET requests,
     *                       regardless of what the client asks for.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $maxLimit = 100,
    ) {
    }

    /**
     * Exposes a resource, making its table reachable through {@see self::handle()}.
     */
    public function register(Resource $resource): static
    {
        $this->resources[$resource->table] = $resource;

        return $this;
    }

    /**
     * Routes a request to the matching resource and CRUD action.
     *
     * The path is expected as `{table}` or `{table}/{id}`. Any error raised
     * while handling the request (unknown resource/operation, validation
     * failure, missing row, ...) is caught and turned into an error
     * {@see Response} rather than propagated — this method never throws.
     *
     * @param array<string, mixed> $context Arbitrary data passed through to every
     *                                       {@see Resource} policy — typically the
     *                                       authenticated user, built by the caller.
     */
    public function handle(Request $request, array $context = []): Response
    {
        $segments = trim($request->path, '/');
        $parts = $segments === '' ? [] : explode('/', $segments);

        $table = array_shift($parts);
        $id = $parts[0] ?? null;

        if ($table === null || !isset($this->resources[$table])) {
            return Response::json(['error' => 'Resource not found'], 404);
        }

        $resource = $this->resources[$table];

        try {
            return match ($request->method) {
                'GET' => $id !== null
                    ? $this->find($resource, $id, $context, $request->query)
                    : $this->list($resource, $request->query, $context),
                'POST' => $this->insert($resource, $request->body, $context),
                'PATCH', 'PUT' => $id !== null
                    ? $this->update($resource, $id, $request->body, $context)
                    : $this->updateNoId($resource, $request->body, $request->query, $context),
                'DELETE' => $id !== null
                    ? $this->delete($resource, $id, $context)
                    : $this->deleteNoId($resource, $request->body, $request->query, $context),
                default => throw new BadRequestException("Unsupported method: {$request->method}"),
            };
        } catch (ApiException $e) {
            return Response::json(['error' => $e->getMessage()], $e->status());
        }
    }

    /**
     * Handles `GET /{table}`.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $context
     * @throws Exceptions\ForbiddenException if `select`, or a requested relation's `select`, has no policy registered.
     * @throws Exceptions\ValidationException on an unknown/malformed filter, select, order or relation column.
     */
    private function list(Resource $resource, array $query, array $context): Response
    {
        $conditions = ($resource->policyFor(Operation::Select))($context);
        $allowed = $resource->allowedColumns();

        [$columns, $embeds] = Filters::select($query['select'] ?? null, $allowed, $resource->relationNames());
        $filters = Filters::parse($query, $allowed);
        $order = Filters::order($query['order'] ?? null, $allowed);
        $limit = min((int) ($query['limit'] ?? self::DEFAULT_LIMIT), $this->maxLimit);
        $offset = max((int) ($query['offset'] ?? 0), 0);

        $extraColumns = array_values(array_diff($this->localJoinColumns($resource, $embeds), $columns));

        [$countSql, $countParams] = QueryBuilder::count($resource->table, $filters, $conditions);
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($countParams);
        $total = (int) $countStmt->fetchColumn();

        [$sql, $params] = QueryBuilder::select(
            $resource->table,
            array_merge($columns, $extraColumns),
            $filters,
            $conditions,
            $order,
            $limit,
            $offset,
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $this->embedRelations($resource, $stmt->fetchAll(), $embeds, $context);

        return Response::json($this->stripColumns($rows, $extraColumns), extraHeaders: [
            'X-Total-Count' => (string) $total,
            'X-Page-Limit' => (string) $limit,
            'X-Page-Offset' => (string) $offset,
        ]);
    }

    /**
     * Handles `GET /{table}/{id}`. $query is only consulted for `select=`
     * (including relation embeds) — callers that just want the full row back
     * (insert/update echoing the written row) pass none.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $query
     * @throws Exceptions\ForbiddenException if `select`, or a requested relation's `select`, has no policy registered.
     * @throws Exceptions\ValidationException on an unknown select or relation column.
     * @throws Exceptions\NotFoundException if no row matches the id and policy conditions.
     */
    private function find(Resource $resource, string $id, array $context, array $query = []): Response
    {
        $conditions = ($resource->policyFor(Operation::Select))($context);
        $idFilter = [[$resource->primaryKey, 'eq', $id]];

        [$columns, $embeds] = Filters::select($query['select'] ?? null, $resource->allowedColumns(), $resource->relationNames());
        $extraColumns = array_values(array_diff($this->localJoinColumns($resource, $embeds), $columns));

        [$sql, $params] = QueryBuilder::select($resource->table, array_merge($columns, $extraColumns), $idFilter, $conditions, null, 1, 0);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new NotFoundException("Resource '{$resource->table}' with id '{$id}' not found");
        }

        $rows = $this->embedRelations($resource, [$row], $embeds, $context);
        $rows = $this->stripColumns($rows, $extraColumns);

        return Response::json($rows[0]);
    }

    /**
     * Handles `POST /{table}`.
     *
     * A body shaped as a list of objects (`[{...}, {...}]`) is treated as a
     * bulk insert, delegated to {@see self::bulkInsert()}; anything else is
     * inserted as a single row.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $context
     * @throws Exceptions\ForbiddenException if `insert` has no policy registered.
     * @throws Exceptions\ValidationException if the body is empty, or references an unknown column.
     */
    private function insert(Resource $resource, array $body, array $context): Response
    {
        if (self::isBulk($body)) {
            return $this->bulkInsert($resource, $body, $context);
        }

        return $this->insertOne($resource, $body, $context);
    }

    /**
     * Inserts every row in $rows within a single transaction — if any row
     * fails, none of them are persisted. Returns the created rows, in order,
     * using a single SELECT after all INSERTs to avoid N+1 queries.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $context
     * @return Response Body is a list of the created rows.
     * @throws Exceptions\ForbiddenException if `insert` or `select` has no policy registered.
     * @throws Exceptions\ValidationException if any row is empty, or references an unknown column.
     */
    private function bulkInsert(Resource $resource, array $rows, array $context): Response
    {
        $insertedIds = [];

        $this->pdo->beginTransaction();

        try {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new ValidationException('Each row must be an object');
                }

                $insertedIds[] = $this->performInsert($resource, $row, $context);
            }
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        $this->pdo->commit();

        $conditions = ($resource->policyFor(Operation::Select))($context);
        $filters = [[$resource->primaryKey, 'in', implode(',', $insertedIds)]];

        [$sql, $params] = QueryBuilder::select(
            $resource->table,
            $resource->allowedColumns(),
            $filters,
            $conditions,
            [[$resource->primaryKey, 'asc']],
            null,
            0,
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return Response::json($stmt->fetchAll());
    }

    /**
     * Inserts a single row and returns it as a Response by re-fetching via {@see self::find()}.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $context
     * @throws Exceptions\ForbiddenException if `insert` has no policy registered.
     * @throws Exceptions\ValidationException if the body is empty, or references an unknown column.
     */
    private function insertOne(Resource $resource, array $body, array $context): Response
    {
        return $this->find($resource, $this->performInsert($resource, $body, $context), $context);
    }

    /**
     * Runs the INSERT and returns the new row's primary key as a string.
     * Policy conditions are merged over the request body, so they always win
     * over client-submitted values for the same columns.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $context
     * @throws Exceptions\ForbiddenException if `insert` has no policy registered.
     * @throws Exceptions\ValidationException if the body is empty, or references an unknown column.
     */
    private function performInsert(Resource $resource, array $body, array $context): string
    {
        $conditions = ($resource->policyFor(Operation::Insert))($context);
        $data = $this->onlyAllowedColumns($resource, $body);
        $data = array_merge($data, self::scalarConditions($conditions));

        if ($data === []) {
            throw new ValidationException('No data to insert');
        }

        [$sql, $params] = QueryBuilder::insert($resource->table, $data);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (string) ($data[$resource->primaryKey] ?? $this->pdo->lastInsertId());
    }

    /**
     * Handles `PATCH|PUT /{table}/{id}`. An id in the path is sugar for a
     * `{pk}=eq.{id}` filter — {@see self::applyUpdate()} is the same code
     * path a filtered `PATCH /{table}?...` (see {@see self::filteredUpdate()})
     * runs through.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $context
     * @throws Exceptions\ForbiddenException if `update` has no policy registered.
     * @throws Exceptions\ValidationException if the body is empty, or references an unknown column.
     * @throws Exceptions\NotFoundException if no row matches the id and policy conditions.
     */
    private function update(Resource $resource, string $id, array $body, array $context): Response
    {
        $rows = $this->applyUpdate($resource, $body, [[$resource->primaryKey, 'eq', $id]], $context);

        if ($rows === []) {
            throw new NotFoundException("Resource '{$resource->table}' with id '{$id}' not found");
        }

        return Response::json($rows[0]);
    }

    /**
     * Runs the UPDATE for every row matching $filters intersected with the
     * `update` policy's conditions, and returns the resulting rows as seen
     * under the `select` policy. Shared by a path-based `/{table}/{id}`
     * update ({@see self::update()}) and a filtered `PATCH /{table}?...`
     * update ({@see self::filteredUpdate()}) — an id in the path is just
     * sugar for a `{pk}=eq.{id}` filter, so both go through here.
     *
     * Policy conditions are merged over the request body — so a column the
     * policy uses to scope rows (`user_id`, `tenant_id`, ...) can never be
     * reassigned by the client just because it also happens to be in the
     * resource's writable whitelist. Without this, a caller could update a
     * row they own while changing its ownership/tenant in the same request,
     * since the WHERE clause only checks the *current* value.
     *
     * @param array<string, mixed> $body
     * @param array<int, array{0: string, 1: string, 2: string}> $filters
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>> The updated rows, as visible under the select
     *                                            policy. Empty when nothing matched under the
     *                                            update policy, or when what did match isn't
     *                                            visible under the select policy.
     * @throws Exceptions\ForbiddenException if `update` has no policy registered.
     * @throws Exceptions\ValidationException if there's no data left to update, or it references an unknown column.
     */
    private function applyUpdate(Resource $resource, array $body, array $filters, array $context): array
    {
        $policyConditions = ($resource->policyFor(Operation::Update))($context);

        $data = $this->onlyAllowedColumns($resource, $body);
        unset($data[$resource->primaryKey]);

        if ($data === []) {
            throw new ValidationException('No data to update');
        }

        $data = array_merge($data, self::scalarConditions($policyConditions));

        // Ids in scope are captured *before* the UPDATE runs — re-applying
        // $filters afterward could miss rows the update itself moved out of
        // the filter's match (e.g. `?title=like.*old*` with a body that
        // changes `title`). Checked against the *update* policy, not
        // $stmt->rowCount() (driver-dependent: SQLite reports rows matched
        // by WHERE, MySQL/MariaDB report rows actually changed, so a no-op
        // update masks a 0 either way).
        [$idsSql, $idsParams] = QueryBuilder::select($resource->table, [$resource->primaryKey], $filters, $policyConditions, null, null, 0);
        $idsStmt = $this->pdo->prepare($idsSql);
        $idsStmt->execute($idsParams);
        $ids = $idsStmt->fetchAll(PDO::FETCH_COLUMN);

        if ($ids === []) {
            return [];
        }

        [$sql, $params] = QueryBuilder::update($resource->table, $data, $policyConditions, $filters);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        // Re-fetched under the *select* policy, not the update conditions —
        // if select is public but update is owner-scoped, a denied update on
        // someone else's row must not resolve and echo the row back anyway.
        $selectConditions = ($resource->policyFor(Operation::Select))($context);
        $idFilter = [[$resource->primaryKey, 'in', implode(',', $ids)]];

        [$selectSql, $selectParams] = QueryBuilder::select(
            $resource->table,
            $resource->allowedColumns(),
            $idFilter,
            $selectConditions,
            [[$resource->primaryKey, 'asc']],
            null,
            0,
        );

        $selectStmt = $this->pdo->prepare($selectSql);
        $selectStmt->execute($selectParams);

        return $selectStmt->fetchAll();
    }

    /**
     * Handles `PATCH|PUT /{table}` with no id — dispatches between a
     * filtered update (query string carries `column=operator.value`
     * filters, e.g. `?status=eq.draft`) and a bulk update (body is a list
     * of row objects, each carrying its own primary key). The two can't be
     * combined in the same request.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @param array<string, mixed> $context
     * @throws Exceptions\BadRequestException if filters and a bulk (array) body are both present,
     *                                          or if neither an id, filters, nor a bulk body is given.
     * @throws Exceptions\ValidationException if a filter targets an unknown column or is malformed.
     */
    private function updateNoId(Resource $resource, array $body, array $query, array $context): Response
    {
        $filters = Filters::parse($query, $resource->allowedColumns());

        if ($filters !== []) {
            if (self::isBulk($body)) {
                throw new BadRequestException('Cannot combine filter query string params with a bulk (array) body');
            }

            return $this->filteredUpdate($resource, $body, $filters, $context);
        }

        return $this->bulkUpdate($resource, $body, $context);
    }

    /**
     * Handles a filtered `PATCH|PUT /{table}?column=operator.value` — updates
     * every row matching both the filters and the `update` policy's
     * conditions, via the same {@see self::applyUpdate()} a path-based
     * `/{table}/{id}` update runs through. Unlike a single/bulk update by
     * id, matching zero rows is not an error: the response is simply an
     * empty list, the same way a `GET` list with no matches returns one.
     *
     * @param array<string, mixed> $body
     * @param array<int, array{0: string, 1: string, 2: string}> $filters
     * @param array<string, mixed> $context
     * @return Response Body is a list of the updated rows.
     * @throws Exceptions\ForbiddenException if `update` has no policy registered.
     * @throws Exceptions\ValidationException if the body is empty, or references an unknown column.
     */
    private function filteredUpdate(Resource $resource, array $body, array $filters, array $context): Response
    {
        return Response::json($this->applyUpdate($resource, $body, $filters, $context));
    }

    /**
     * Handles `PATCH|PUT /{table}` with no id — a bulk update. Each row in
     * the body must include the resource's primary key, identifying which
     * row it updates; the rest of its keys are the columns to change. Runs
     * in a single transaction — if any row fails, none of them are applied.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $context
     * @return Response Body is a list of the updated rows.
     * @throws Exceptions\BadRequestException if $body isn't a list of row objects.
     * @throws Exceptions\ForbiddenException if `update` has no policy registered.
     * @throws Exceptions\ValidationException if a row is missing the primary key,
     *                                          is empty otherwise, or references an unknown column.
     * @throws Exceptions\NotFoundException if a row's id has no match under the policy conditions.
     */
    private function bulkUpdate(Resource $resource, array $body, array $context): Response
    {
        if (!self::isBulk($body)) {
            throw new BadRequestException(
                'An id is required to update a resource, send a list of row objects to update in bulk, '
                . 'or filter query string params to update every matching row',
            );
        }

        $updated = [];

        $this->pdo->beginTransaction();

        try {
            foreach ($body as $row) {
                if (!is_array($row) || !array_key_exists($resource->primaryKey, $row)) {
                    throw new ValidationException("Each row must include '{$resource->primaryKey}'");
                }

                $updated[] = $this->update($resource, (string) $row[$resource->primaryKey], $row, $context)->body;
            }
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        $this->pdo->commit();

        return Response::json($updated);
    }

    /**
     * Handles `DELETE /{table}/{id}`. An id in the path is sugar for a
     * `{pk}=eq.{id}` filter — {@see self::applyDelete()} is the same code
     * path a filtered `DELETE /{table}?...` (see {@see self::filteredDelete()})
     * runs through.
     *
     * @param array<string, mixed> $context
     * @throws Exceptions\ForbiddenException if `delete` has no policy registered.
     * @throws Exceptions\NotFoundException if no row matches the id and policy conditions.
     */
    private function delete(Resource $resource, string $id, array $context): Response
    {
        if ($this->applyDelete($resource, [[$resource->primaryKey, 'eq', $id]], $context) === 0) {
            throw new NotFoundException("Resource '{$resource->table}' with id '{$id}' not found");
        }

        return Response::json(null, 204);
    }

    /**
     * Runs the DELETE for every row matching $filters intersected with the
     * `delete` policy's conditions. Shared by a path-based `/{table}/{id}`
     * delete ({@see self::delete()}) and a filtered `DELETE /{table}?...`
     * delete ({@see self::filteredDelete()}) — an id in the path is just
     * sugar for a `{pk}=eq.{id}` filter, so both go through here.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $filters
     * @param array<string, mixed> $context
     * @return int Number of rows actually deleted.
     * @throws Exceptions\ForbiddenException if `delete` has no policy registered.
     */
    private function applyDelete(Resource $resource, array $filters, array $context): int
    {
        $conditions = ($resource->policyFor(Operation::Delete))($context);

        [$sql, $params] = QueryBuilder::delete($resource->table, $conditions, $filters);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Handles `DELETE /{table}` with no id — dispatches between a filtered
     * delete (query string carries `column=operator.value` filters, e.g.
     * `?views=lt.10`) and a bulk delete (body is a list of primary key
     * values). The two can't be combined in the same request.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @param array<string, mixed> $context
     * @throws Exceptions\BadRequestException if filters and a body are both present,
     *                                          or if neither an id, filters, nor a bulk body is given.
     * @throws Exceptions\ValidationException if a filter targets an unknown column or is malformed.
     */
    private function deleteNoId(Resource $resource, array $body, array $query, array $context): Response
    {
        $filters = Filters::parse($query, $resource->allowedColumns());

        if ($filters !== []) {
            if ($body !== []) {
                throw new BadRequestException('Cannot combine filter query string params with a delete body');
            }

            return $this->filteredDelete($resource, $filters, $context);
        }

        return $this->bulkDelete($resource, $body, $context);
    }

    /**
     * Handles a filtered `DELETE /{table}?column=operator.value` — deletes
     * every row matching both the filters and the `delete` policy's
     * conditions, via the same {@see self::applyDelete()} a path-based
     * `/{table}/{id}` delete runs through. Unlike a single/bulk delete by
     * id, matching zero rows is not an error — the response is `204`
     * either way.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $filters
     * @param array<string, mixed> $context
     * @throws Exceptions\ForbiddenException if `delete` has no policy registered.
     */
    private function filteredDelete(Resource $resource, array $filters, array $context): Response
    {
        $this->applyDelete($resource, $filters, $context);

        return Response::json(null, 204);
    }

    /**
     * Handles `DELETE /{table}` with no id — a bulk delete. $body must be a
     * list of primary key values (`[1, 2, 3]`), each identifying a row to
     * delete. Runs in a single transaction — if any id fails (unknown, or
     * outside the policy's conditions), none of them are deleted.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $context
     * @throws Exceptions\BadRequestException if $body isn't a non-empty list of ids.
     * @throws Exceptions\ValidationException if an id is itself an array/object.
     * @throws Exceptions\ForbiddenException if `delete` has no policy registered.
     * @throws Exceptions\NotFoundException if an id has no match under the policy conditions.
     */
    private function bulkDelete(Resource $resource, array $body, array $context): Response
    {
        if ($body === [] || !array_is_list($body)) {
            throw new BadRequestException(
                'An id is required to delete a resource, send a list of ids to delete in bulk, '
                . 'or filter query string params to delete every matching row',
            );
        }

        $this->pdo->beginTransaction();

        try {
            foreach ($body as $id) {
                if (is_array($id)) {
                    throw new ValidationException('Each id must be a scalar value');
                }

                $this->delete($resource, (string) $id, $context);
            }
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        $this->pdo->commit();

        return Response::json(null, 204);
    }

    /**
     * The parent-row columns needed to match against each requested embed —
     * the resource's own primary key for a hasMany (grouped by the related
     * table's foreign key), or the resource's own foreign key column for a
     * belongsTo (matched against the related table's primary key). Forced
     * into the query even when the caller's select= didn't ask for them;
     * {@see self::stripColumns()} removes them again afterward if so.
     *
     * @param array<string, string[]> $embeds
     * @return string[]
     */
    private function localJoinColumns(Resource $resource, array $embeds): array
    {
        $columns = [];

        foreach (array_keys($embeds) as $name) {
            $relation = $resource->relation($name);

            if ($relation === null) {
                continue; // Filters::select() already rejects unknown relations before this runs.
            }

            $column = $relation->type === RelationType::HasMany ? $resource->primaryKey : $relation->foreignKey;

            if (!in_array($column, $columns, true)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string[] $columns
     * @return array<int, array<string, mixed>>
     */
    private function stripColumns(array $rows, array $columns): array
    {
        if ($columns === []) {
            return $rows;
        }

        foreach ($rows as &$row) {
            foreach ($columns as $column) {
                unset($row[$column]);
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Loads and attaches each requested relation onto $rows — a hasMany
     * relation becomes an array under the relation name, a belongsTo becomes
     * a single row or null. Runs one query per relation (not per row): all
     * parent keys are gathered up front and matched with a single `IN (...)`
     * query, scoped by the related resource's own `select` policy exactly
     * like a direct request to it would be.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string[]> $embeds Relation name => requested nested columns (empty means "all").
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     * @throws Exceptions\ForbiddenException if a relation's target resource has no `select` policy registered.
     * @throws Exceptions\ValidationException if a requested nested column isn't in the related resource's whitelist.
     * @throws \LogicException if a relation's target table was never registered on this Api — a setup bug,
     *                          not a client error, so it is deliberately not turned into an error Response.
     */
    private function embedRelations(Resource $resource, array $rows, array $embeds, array $context): array
    {
        if ($rows === [] || $embeds === []) {
            return $rows;
        }

        foreach ($embeds as $name => $nestedColumns) {
            $relation = $resource->relation($name)
                ?? throw new \LogicException("Relation '{$name}' was validated but is missing — this is a bug in pdo-restify.");

            $related = $this->resources[$relation->table]
                ?? throw new \LogicException(
                    "Relation '{$name}' on '{$resource->table}' points to table '{$relation->table}', "
                    . "which is not registered on this Api. Did you forget to register() it?",
                );

            $allowed = $related->allowedColumns();

            foreach ($nestedColumns as $column) {
                if (!in_array($column, $allowed, true)) {
                    throw new ValidationException("Unknown column '{$column}' on relation '{$name}'");
                }
            }

            $joinColumn = $relation->type === RelationType::HasMany ? $relation->foreignKey : $related->primaryKey;
            $localColumn = $relation->type === RelationType::HasMany ? $resource->primaryKey : $relation->foreignKey;

            $selectColumns = $nestedColumns === [] ? $allowed : $nestedColumns;
            $keepJoinColumn = in_array($joinColumn, $selectColumns, true);
            if (!$keepJoinColumn) {
                $selectColumns[] = $joinColumn;
            }

            $keys = array_values(array_unique(array_filter(
                array_column($rows, $localColumn),
                static fn (mixed $value): bool => $value !== null,
            )));

            $grouped = [];

            if ($keys !== []) {
                $conditions = ($related->policyFor(Operation::Select))($context);
                $filters = [[$joinColumn, 'in', implode(',', $keys)]];

                [$sql, $params] = QueryBuilder::select($related->table, $selectColumns, $filters, $conditions, null, null, 0);

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);

                foreach ($stmt->fetchAll() as $relatedRow) {
                    $key = $relatedRow[$joinColumn];

                    if (!$keepJoinColumn) {
                        unset($relatedRow[$joinColumn]);
                    }

                    if ($relation->type === RelationType::HasMany) {
                        $grouped[$key][] = $relatedRow;
                    } else {
                        $grouped[$key] = $relatedRow;
                    }
                }
            }

            foreach ($rows as &$row) {
                $key = $row[$localColumn] ?? null;

                $row[$name] = $relation->type === RelationType::HasMany
                    ? ($grouped[$key] ?? [])
                    : ($grouped[$key] ?? null);
            }
            unset($row);
        }

        return $rows;
    }

    /**
     * Filters a request body down to the resource's column whitelist.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws Exceptions\ValidationException if the body references a column that isn't whitelisted.
     */
    private function onlyAllowedColumns(Resource $resource, array $body): array
    {
        $allowed = $resource->allowedColumns();
        $data = [];

        foreach ($body as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                throw new ValidationException("Unknown column: {$column}");
            }

            $data[$column] = $value;
        }

        return $data;
    }

    /**
     * A body is treated as a bulk request when it's a non-empty list
     * (sequential integer keys from 0) whose first element is itself an
     * array — i.e. `[{...}, {...}]` rather than a single `{...}` object.
     *
     * @param array<string, mixed> $body
     */
    private static function isBulk(array $body): bool
    {
        return $body !== [] && array_is_list($body) && is_array($body[0]);
    }

    /**
     * Filters out operator-based conditions (arrays with an `op` key) from a
     * policy condition map, keeping only scalar equality conditions. Used when
     * merging policy conditions into INSERT/UPDATE data — operator conditions
     * like `is_null` or `gt` are meaningful in WHERE clauses but not as values
     * to write. They still appear in the $whereConditions / $conditions passed
     * to QueryBuilder, which handles them correctly.
     *
     * @param array<string, mixed> $conditions
     * @return array<string, mixed>
     */
    private static function scalarConditions(array $conditions): array
    {
        return array_filter(
            $conditions,
            static fn (mixed $v): bool => !is_array($v) || !isset($v['op']),
        );
    }
}
