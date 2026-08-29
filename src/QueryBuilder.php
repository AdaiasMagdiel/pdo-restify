<?php

declare(strict_types=1);

namespace AdaiasMagdiel\PdoRestify;

/**
 * Turns already-validated table/column names, filter tuples, and a
 * {@see RawCondition} into parameterized SQL. Every value is bound, never
 * interpolated — except a {@see RawCondition}'s `sql`, which is merged in
 * verbatim by design (see its docblock). Identifiers (table/column names)
 * *are* interpolated, since SQL has no placeholder syntax for them — every
 * one that reaches a query string here is first re-checked with
 * {@see Resource::assertIdentifier()}, regardless of whether the caller
 * already validated it.
 *
 * Every method returns an `[sql, params]` tuple ready for
 * `PDO::prepare()`/`PDOStatement::execute()`.
 */
final class QueryBuilder
{
    /**
     * Builds a `SELECT ... FROM table WHERE ... ORDER BY ... LIMIT ... OFFSET ...` query.
     *
     * @param string[] $columns
     * @param array<int, array{0: string, 1: string, 2: string}> $filters Tuples of [column, operator, rawValue], as returned by {@see Filters::parse()}.
     * @param RawCondition|null $condition A {@see Resource} policy's row-scoping expression, ANDed with $filters. Null means unrestricted.
     * @param list<array{0: string, 1: string}>|null $order List of [column, direction] pairs as returned by {@see Filters::order()}.
     * @param int|null $limit Null omits the LIMIT/OFFSET clause entirely — used internally when
     *                        loading relations, where every matching related row is wanted.
     * @return array{0: string, 1: array<string, mixed>}
     * @throws \InvalidArgumentException if $table, a column, or a filter column is not a valid SQL identifier.
     */
    public static function select(
        string $table,
        array $columns,
        array $filters,
        ?RawCondition $condition,
        ?array $order,
        ?int $limit,
        int $offset,
    ): array {
        self::assertIdentifiers([$table, ...$columns, ...array_column($filters, 0)]);
        if ($order !== null) {
            self::assertIdentifiers(array_column($order, 0));
        }

        $select = implode(', ', $columns);
        $sql = "SELECT {$select} FROM {$table}";

        [$where, $params] = self::buildWhere($filters, $condition);
        if ($where !== '') {
            $sql .= " WHERE {$where}";
        }

        if ($order !== null) {
            $orderClauses = array_map(
                static fn (array $o): string => "{$o[0]} " . strtoupper($o[1]),
                $order,
            );
            $sql .= ' ORDER BY ' . implode(', ', $orderClauses);
        }

        if ($limit !== null) {
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }

        return [$sql, $params];
    }

    /**
     * Builds a `SELECT COUNT(*) AS total FROM table WHERE ...` query, using the
     * same filters and condition as {@see self::select()} so callers can get an
     * accurate total count for pagination without fetching all rows.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $filters
     * @return array{0: string, 1: array<string, mixed>}
     * @throws \InvalidArgumentException if $table or a filter column is not a valid SQL identifier.
     */
    public static function count(string $table, array $filters, ?RawCondition $condition): array
    {
        self::assertIdentifiers([$table, ...array_column($filters, 0)]);

        $sql = "SELECT COUNT(*) AS total FROM {$table}";

        [$where, $params] = self::buildWhere($filters, $condition);
        if ($where !== '') {
            $sql .= " WHERE {$where}";
        }

        return [$sql, $params];
    }

    /**
     * Builds an `INSERT INTO table (...) VALUES (...)` query. Carries no
     * condition — enforcing a policy's WITH CHECK on an insert happens after
     * this runs, by re-querying the new row (see {@see Api::performInsert()}).
     *
     * @param array<string, mixed> $data Column => value pairs to insert.
     * @return array{0: string, 1: array<string, mixed>}
     * @throws \InvalidArgumentException if $table or a column of $data is not a valid SQL identifier.
     */
    public static function insert(string $table, array $data): array
    {
        self::assertIdentifiers([$table, ...array_keys($data)]);

        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ":i_{$c}", $columns);

        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';

        $params = [];
        foreach ($data as $column => $value) {
            $params[":i_{$column}"] = $value;
        }

        return [$sql, $params];
    }

    /**
     * Builds an `UPDATE table SET ... WHERE ...` query.
     *
     * @param array<string, mixed> $data Column => value pairs to set.
     * @param RawCondition|null $condition A {@see Resource} policy's row-scoping expression (USING), ANDed with $filters.
     * @param array<int, array{0: string, 1: string, 2: string}> $filters Tuples of [column, operator, rawValue],
     *                                                                     as returned by {@see Filters::parse()},
     *                                                                     ANDed with $condition.
     * @return array{0: string, 1: array<string, mixed>}
     * @throws \InvalidArgumentException if $table, a column of $data, or a filter column is not a valid SQL identifier.
     */
    public static function update(string $table, array $data, ?RawCondition $condition, array $filters = []): array
    {
        self::assertIdentifiers([$table, ...array_keys($data), ...array_column($filters, 0)]);

        $sets = [];
        $params = [];

        foreach ($data as $column => $value) {
            $sets[] = "{$column} = :s_{$column}";
            $params[":s_{$column}"] = $value;
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $sets);

        [$where, $whereParams] = self::buildWhere($filters, $condition);
        if ($where !== '') {
            $sql .= " WHERE {$where}";
        }

        return [$sql, $params + $whereParams];
    }

    /**
     * Builds a `DELETE FROM table WHERE ...` query.
     *
     * @param RawCondition|null $condition A {@see Resource} policy's row-scoping expression, ANDed with $filters.
     * @param array<int, array{0: string, 1: string, 2: string}> $filters Tuples of [column, operator, rawValue],
     *                                                                     as returned by {@see Filters::parse()},
     *                                                                     ANDed with $condition.
     * @return array{0: string, 1: array<string, mixed>}
     * @throws \InvalidArgumentException if $table or a filter column is not a valid SQL identifier.
     */
    public static function delete(string $table, ?RawCondition $condition, array $filters = []): array
    {
        self::assertIdentifiers([$table, ...array_column($filters, 0)]);

        $sql = "DELETE FROM {$table}";

        [$where, $params] = self::buildWhere($filters, $condition);
        if ($where !== '') {
            $sql .= " WHERE {$where}";
        }

        return [$sql, $params];
    }

    /**
     * Combines a policy's $condition and operator-based $filters into a single
     * `AND`-joined WHERE clause.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $filters
     * @return array{0: string, 1: array<string, mixed>} Empty string/array when there's nothing to filter on.
     */
    private static function buildWhere(array $filters, ?RawCondition $condition): array
    {
        $clauses = [];
        $params = [];

        if ($condition !== null) {
            $clauses[] = "({$condition->sql})";
            $params = $condition->params;
        }

        $i = 0;

        foreach ($filters as [$column, $operator, $value]) {
            $ph = ":f{$i}";

            switch ($operator) {
                case 'eq':
                    $clauses[] = "{$column} = {$ph}";
                    $params[$ph] = $value;
                    break;
                case 'ne':
                    $clauses[] = "{$column} != {$ph}";
                    $params[$ph] = $value;
                    break;
                case 'gt':
                    $clauses[] = "{$column} > {$ph}";
                    $params[$ph] = $value;
                    break;
                case 'gte':
                    $clauses[] = "{$column} >= {$ph}";
                    $params[$ph] = $value;
                    break;
                case 'lt':
                    $clauses[] = "{$column} < {$ph}";
                    $params[$ph] = $value;
                    break;
                case 'lte':
                    $clauses[] = "{$column} <= {$ph}";
                    $params[$ph] = $value;
                    break;
                case 'like':
                    $clauses[] = "{$column} LIKE {$ph}";
                    $params[$ph] = str_replace('*', '%', $value);
                    break;
                case 'in':
                    $values = explode(',', $value);
                    $names = [];
                    foreach ($values as $j => $v) {
                        $inPh = "{$ph}_{$j}";
                        $names[] = $inPh;
                        $params[$inPh] = $v;
                    }
                    $clauses[] = "{$column} IN (" . implode(', ', $names) . ')';
                    break;
                case 'not_in':
                    $values = explode(',', $value);
                    $names = [];
                    foreach ($values as $j => $v) {
                        $inPh = "{$ph}_{$j}";
                        $names[] = $inPh;
                        $params[$inPh] = $v;
                    }
                    $clauses[] = "{$column} NOT IN (" . implode(', ', $names) . ')';
                    break;
                case 'is_null':
                    $clauses[] = "{$column} IS NULL";
                    break;
                case 'is_not_null':
                    $clauses[] = "{$column} IS NOT NULL";
                    break;
            }

            $i++;
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * @param string[] $names
     * @throws \InvalidArgumentException if any of $names is not a valid, unquoted SQL identifier.
     */
    private static function assertIdentifiers(array $names): void
    {
        foreach ($names as $name) {
            Resource::assertIdentifier($name);
        }
    }
}
