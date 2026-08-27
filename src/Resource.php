<?php

declare(strict_types=1);

namespace AdaiasMagdiel\PdoRestify;

use AdaiasMagdiel\PdoRestify\Exceptions\ForbiddenException;

/**
 * A database table exposed through {@see Api}.
 *
 * A table is unreachable until it is wrapped in a Resource, given an
 * explicit column whitelist via {@see self::columns()}, and has at least one
 * operation enabled via {@see self::allow()} — deny by default, at the table
 * and operation level.
 */
final class Resource
{
    /** @var string[] Columns readable/writable through this resource; empty means none. */
    private array $columns = [];

    /** @var array<string, \Closure> Policy closures registered via {@see self::allow()}, keyed by {@see Operation::value}. */
    private array $policies = [];

    /**
     * @param string $table Table name. Validated so it can never carry SQL.
     * @param string $primaryKey Column used to address a single row (`GET|PATCH|DELETE /{table}/{id}`).
     * @throws \InvalidArgumentException if $table or $primaryKey is not a valid SQL identifier.
     */
    public function __construct(
        public readonly string $table,
        public readonly string $primaryKey = 'id',
    ) {
        self::assertIdentifier($table);
        self::assertIdentifier($primaryKey);
    }

    /**
     * Sets the whitelist of columns readable through `select` and writable
     * through `insert`/`update`. Any column not listed here is rejected,
     * whether it comes from a filter, a `select=` param, or the request body.
     *
     * @param string[] $columns
     * @throws \InvalidArgumentException if any column is not a valid SQL identifier.
     */
    public function columns(array $columns): static
    {
        foreach ($columns as $column) {
            self::assertIdentifier($column);
        }

        $this->columns = $columns;

        return $this;
    }

    /**
     * @return string[] The whitelist set by {@see self::columns()}.
     */
    public function allowedColumns(): array
    {
        return $this->columns;
    }

    /**
     * Enables an operation on this resource. The optional policy closure
     * receives the request context and returns an associative array of
     * column => value conditions that are always enforced, regardless of
     * client input — this is how you scope rows like PostgreSQL's RLS would.
     *
     * A policy is not required: call allow(Operation::Select) with no closure
     * to expose an operation with no row-level restriction at all. Nothing in
     * pdo-restify forces you into a scoping scheme — that choice is yours.
     *
     * @param (\Closure(array<string, mixed>): array<string, mixed>)|null $policy
     */
    public function allow(Operation $operation, ?\Closure $policy = null): static
    {
        $this->policies[$operation->value] = $policy ?? static fn (array $context = []): array => [];

        return $this;
    }

    /**
     * @return \Closure(array<string, mixed>): array<string, mixed>
     * @throws ForbiddenException if $operation was never enabled via {@see self::allow()}.
     */
    public function policyFor(Operation $operation): \Closure
    {
        return $this->policies[$operation->value]
            ?? throw new ForbiddenException("Operation '{$operation->value}' is not allowed on '{$this->table}'");
    }

    /**
     * @throws \InvalidArgumentException if $name is not a valid, unquoted SQL identifier.
     */
    public static function assertIdentifier(string $name): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Invalid identifier: {$name}");
        }
    }
}
