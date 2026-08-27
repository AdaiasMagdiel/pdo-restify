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
                    ? $this->find($resource, $id, $context)
                    : $this->list($resource, $request->query, $context),
                'POST' => $this->insert($resource, $request->body, $context),
                'PATCH', 'PUT' => $id !== null
                    ? $this->update($resource, $id, $request->body, $context)
                    : throw new BadRequestException('An id is required to update a resource'),
                'DELETE' => $id !== null
                    ? $this->delete($resource, $id, $context)
                    : throw new BadRequestException('An id is required to delete a resource'),
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
     * @throws Exceptions\ForbiddenException if `select` has no policy registered.
     * @throws Exceptions\ValidationException on an unknown/malformed filter, select or order column.
     */
    private function list(Resource $resource, array $query, array $context): Response
    {
        $conditions = ($resource->policyFor('select'))($context);
        $allowed = $resource->allowedColumns();

        $columns = Filters::select($query['select'] ?? null, $allowed);
        $filters = Filters::parse($query, $allowed);
        $order = Filters::order($query['order'] ?? null, $allowed);
        $limit = min((int) ($query['limit'] ?? self::DEFAULT_LIMIT), $this->maxLimit);
        $offset = max((int) ($query['offset'] ?? 0), 0);

        [$sql, $params] = QueryBuilder::select($resource->table, $columns, $filters, $conditions, $order, $limit, $offset);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return Response::json($stmt->fetchAll());
    }

    /**
     * Handles `GET /{table}/{id}`.
     *
     * @param array<string, mixed> $context
     * @throws Exceptions\ForbiddenException if `select` has no policy registered.
     * @throws Exceptions\NotFoundException if no row matches the id and policy conditions.
     */
    private function find(Resource $resource, string $id, array $context): Response
    {
        $conditions = ($resource->policyFor('select'))($context);
        $conditions[$resource->primaryKey] = $id;

        [$sql, $params] = QueryBuilder::select($resource->table, $resource->allowedColumns(), [], $conditions, null, 1, 0);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new NotFoundException("Resource '{$resource->table}' with id '{$id}' not found");
        }

        return Response::json($row);
    }

    /**
     * Handles `POST /{table}`.
     *
     * Policy conditions are merged over the request body, so they always win
     * over client-submitted values for the same columns.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $context
     * @throws Exceptions\ForbiddenException if `insert` has no policy registered.
     * @throws Exceptions\ValidationException if the body is empty, or references an unknown column.
     */
    private function insert(Resource $resource, array $body, array $context): Response
    {
        $conditions = ($resource->policyFor('insert'))($context);
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
        $conditions = ($resource->policyFor('update'))($context);
        $conditions[$resource->primaryKey] = $id;

        $data = $this->onlyAllowedColumns($resource, $body);
        unset($data[$resource->primaryKey]);

        if ($data === []) {
            throw new ValidationException('No data to update');
        }

        [$sql, $params] = QueryBuilder::update($resource->table, $data, $conditions);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            throw new NotFoundException("Resource '{$resource->table}' with id '{$id}' not found");
        }

        return $this->find($resource, $id, $context);
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
        $conditions = ($resource->policyFor('delete'))($context);
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
}
