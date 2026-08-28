<?php

declare(strict_types=1);

namespace AdaiasMagdiel\PdoRestify;

/**
 * Turns already-validated table/column names and filter tuples into
 * parameterized SQL. Every value is bound, never interpolated. Identifiers
 * (table/column names) *are* interpolated, since SQL has no placeholder
 * syntax for them — every one that reaches a query string here is first
 * re-checked with {@see Resource::assertIdentifier()}, regardless of
 * whether the caller already validated it. This class trusts no caller: a
 * {@see Resource} policy closure's condition keys, for instance, are
 * ordinary application code that was never required to whitelist-check
 * them the way {@see Filters} does for request input — this is the one
 * place that guarantee is enforced unconditionally.
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
     * @param array<string, mixed> $conditions Equality conditions (e.g. from a {@see Resource} policy), ANDed with $filters.
     * @param list<array{0: string, 1: string}>|null $order List of [column, direction] pairs as returned by {@see Filters::order()}.
     * @param int|null $limit Null omits the LIMIT/OFFSET clause entirely — used internally when
     *                        loading relations, where every matching related row is wanted.
     * @return array{0: string, 1: array<string, mixed>}
     * @throws \InvalidArgumentException if $table, a column, a filter column, a condition key,
     *                                    or an order column is not a valid SQL identifier.
     */
    public static function select(
        string $table,
        array $columns,
        array $filters,
        array $conditions,
        ?array $order,
        ?int $limit,
        int $offset,
    ): array {
        self::assertIdentifiers([$table, ...$columns, ...array_keys($conditions), ...array_column($filters, 0)]);
        if ($order !== null) {
            self::assertIdentifiers(array_column($order, 0));
        }

        $select = implode(', ', $columns);
        $sql = "SELECT {$select} FROM {$table}";

        [$where, $params] = self::buildWhere($filters, $conditions);
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
     * same filters and conditions as {@see self::select()} so callers can get an
     * accurate total count for pagination without fetching all rows.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $filters
     * @param array<string, mixed> $conditions
     * @return array{0: string, 1: array<string, mixed>}
     * @throws \InvalidArgumentException if $table, a filter column, or a condition key is not a valid SQL identifier.
     */
    public static function count(string $table, array $filters, array $conditions): array
    {
        self::assertIdentifiers([$table, ...array_keys($conditions), ...array_column($filters, 0)]);

        $sql = "SELECT COUNT(*) AS total FROM {$table}";

        [$where, $params] = self::buildWhere($filters, $conditions);
        if ($where !== '') {
            $sql .= " WHERE {$where}";
        }

        return [$sql, $params];
    }

    /**
     * Builds an `INSERT INTO table (...) VALUES (...)` query.
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
     * @param array<string, mixed> $conditions Equality conditions scoping which rows are updated.
     * @return array{0: string, 1: array<string, mixed>}
     * @throws \InvalidArgumentException if $table, a column of $data, or a condition key
     *                                    is not a valid SQL identifier.
     */
    public static function update(string $table, array $data, array $conditions): array
    {
        self::assertIdentifiers([$table, ...array_keys($data), ...array_keys($conditions)]);

        $sets = [];
        $params = [];

        foreach ($data as $column => $value) {
            $sets[] = "{$column} = :s_{$column}";
            $params[":s_{$column}"] = $value;
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $sets);

        [$where, $whereParams] = self::buildWhere([], $conditions);
        if ($where !== '') {
            $sql .= " WHERE {$where}";
        }

        return [$sql, $params + $whereParams];
    }

    /**
     * Builds a `DELETE FROM table WHERE ...` query.
     *
     * @param array<string, mixed> $conditions Equality conditions scoping which rows are deleted.
     * @return array{0: string, 1: array<string, mixed>}
     * @throws \InvalidArgumentException if $table or a condition key is not a valid SQL identifier.
     */
    public static function delete(string $table, array $conditions): array
    {
        self::assertIdentifiers([$table, ...array_keys($conditions)]);

        $sql = "DELETE FROM {$table}";

        [$where, $params] = self::buildWhere([], $conditions);
        if ($where !== '') {
            $sql .= " WHERE {$where}";
        }

        return [$sql, $params];
    }

    /**
     * Combines equality $conditions and operator-based $filters into a single
     * `AND`-joined WHERE clause, one placeholder per bound value.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $filters
     * @param array<string, mixed> $conditions
     * @return array{0: string, 1: array<string, mixed>} Empty string/array when there's nothing to filter on.
     */
    private static function buildWhere(array $filters, array $conditions): array
    {
        $clauses = [];
        $params = [];
        $i = 0;

        foreach ($conditions as $column => $value) {
            if (is_array($value) && isset($value['op'])) {
                $op  = $value['op'];
                $val = $value['value'] ?? null;

                if ($op === 'is_null') {
                    $clauses[] = "{$column} IS NULL";
                } elseif ($op === 'is_not_null') {
                    $clauses[] = "{$column} IS NOT NULL";
                } elseif (in_array($op, ['eq', 'ne', 'gt', 'gte', 'lt', 'lte'], true)) {
                    $ph = ":c{$i}";
                    $sqlOp = match ($op) {
                        'eq'  => '=',
                        'ne'  => '!=',
                        'gt'  => '>',
                        'gte' => '>=',
                        'lt'  => '<',
                        'lte' => '<=',
                    };
                    $clauses[]    = "{$column} {$sqlOp} {$ph}";
                    $params[$ph]  = $val;
                }
            } else {
                $ph = ":c{$i}";
                $clauses[] = "{$column} = {$ph}";
                $params[$ph] = $value;
            }
            $i++;
        }

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
