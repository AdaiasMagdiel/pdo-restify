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
                    : $this->bulkUpdate($resource, $request->body, $context),
                'DELETE' => $id !== null
                    ? $this->delete($resource, $id, $context)
                    : $this->bulkDelete($resource, $request->body, $context),
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

        return Response::json($this->stripColumns($rows, $extraColumns));
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
        $conditions[$resource->primaryKey] = $id;

        [$columns, $embeds] = Filters::select($query['select'] ?? null, $resource->allowedColumns(), $resource->relationNames());
        $extraColumns = array_values(array_diff($this->localJoinColumns($resource, $embeds), $columns));

        [$sql, $params] = QueryBuilder::select($resource->table, array_merge($columns, $extraColumns), [], $conditions, null, 1, 0);

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
     * fails, none of them are persisted. Returns the created rows, in order.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $context
     * @return Response Body is a list of the created rows.
     * @throws Exceptions\ForbiddenException if `insert` has no policy registered.
     * @throws Exceptions\ValidationException if any row is empty, or references an unknown column.
     */
    private function bulkInsert(Resource $resource, array $rows, array $context): Response
    {
        $created = [];

        $this->pdo->beginTransaction();

        try {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new ValidationException('Each row must be an object');
                }

                $created[] = $this->insertOne($resource, $row, $context)->body;
            }
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        $this->pdo->commit();

        return Response::json($created);
    }

    /**
     * Inserts a single row.
     *
     * Policy conditions are merged over the request body, so they always win
     * over client-submitted values for the same columns.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $context
     * @throws Exceptions\ForbiddenException if `insert` has no policy registered.
     * @throws Exceptions\ValidationException if the body is empty, or references an unknown column.
     */
    private function insertOne(Resource $resource, array $body, array $context): Response
    {
        $conditions = ($resource->policyFor(Operation::Insert))($context);
        $data = $this->onlyAllowedColumns($resource, $body);
        $data = array_merge($data, $conditions);

        if ($data === []) {
            throw new ValidationException('No data to insert');
        }

        [$sql, $params] = QueryBuilder::insert($resource->table, $data);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $id = $data[$resource->primaryKey] ?? $this->pdo->lastInsertId();

        return $this->find($resource, (string) $id, $context);
    }

    /**
     * Handles `PATCH|PUT /{table}/{id}`.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $context
     * @throws Exceptions\ForbiddenException if `update` has no policy registered.
     * @throws Exceptions\ValidationException if the body is empty, or references an unknown column.
     * @throws Exceptions\NotFoundException if no row matches the id and policy conditions.
     */
    private function update(Resource $resource, string $id, array $body, array $context): Response
    {
        $conditions = ($resource->policyFor(Operation::Update))($context);
        $conditions[$resource->primaryKey] = $id;

        $data = $this->onlyAllowedColumns($resource, $body);
        unset($data[$resource->primaryKey]);

        if ($data === []) {
            throw new ValidationException('No data to update');
        }

        [$sql, $params] = QueryBuilder::update($resource->table, $data, $conditions);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        // Deliberately not checking $stmt->rowCount() here: PDO's UPDATE row
        // count is driver-dependent — SQLite reports rows matched by WHERE,
        // MySQL/MariaDB report rows actually changed by default, so a no-op
        // update (new values equal the old ones) reports 0 there even though
        // the row exists and matched. find() below re-checks existence with
        // an actual SELECT, which has no such ambiguity on any driver.
        return $this->find($resource, $id, $context);
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
                'An id is required to update a resource, or send a list of row objects to update in bulk',
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
     * Handles `DELETE /{table}/{id}`.
     *
     * @param array<string, mixed> $context
     * @throws Exceptions\ForbiddenException if `delete` has no policy registered.
     * @throws Exceptions\NotFoundException if no row matches the id and policy conditions.
     */
    private function delete(Resource $resource, string $id, array $context): Response
    {
        $conditions = ($resource->policyFor(Operation::Delete))($context);
        $conditions[$resource->primaryKey] = $id;

        [$sql, $params] = QueryBuilder::delete($resource->table, $conditions);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            throw new NotFoundException("Resource '{$resource->table}' with id '{$id}' not found");
        }

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
                'An id is required to delete a resource, or send a list of ids to delete in bulk',
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
}
