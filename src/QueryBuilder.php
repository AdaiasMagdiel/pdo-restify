<?php

declare(strict_types=1);

namespace AdaiasMagdiel\PdoRestify;

/**
 * Turns already-validated table/column names and filter tuples into
 * parameterized SQL. Every value is bound, never interpolated; the only
 * strings interpolated into SQL here are identifiers that callers ({@see
 * Resource}, {@see Filters}) have already checked against a whitelist.
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
     * @param array{0: string, 1: string}|null $order [column, direction] as returned by {@see Filters::order()}.
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function select(
        string $table,
        array $columns,
        array $filters,
        array $conditions,
        ?array $order,
        int $limit,
        int $offset,
    ): array {
        $select = implode(', ', $columns);
        $sql = "SELECT {$select} FROM {$table}";

        [$where, $params] = self::buildWhere($filters, $conditions);
        if ($where !== '') {
            $sql .= " WHERE {$where}";
        }

        if ($order !== null) {
            $sql .= " ORDER BY {$order[0]} " . strtoupper($order[1]);
        }

        $sql .= " LIMIT {$limit} OFFSET {$offset}";

        return [$sql, $params];
    }

    /**
     * Builds an `INSERT INTO table (...) VALUES (...)` query.
     *
     * @param array<string, mixed> $data Column => value pairs to insert.
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function insert(string $table, array $data): array
    {
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
     */
    public static function update(string $table, array $data, array $conditions): array
    {
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
     */
    public static function delete(string $table, array $conditions): array
    {
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
            $ph = ":c{$i}";
            $clauses[] = "{$column} = {$ph}";
            $params[$ph] = $value;
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
            }

            $i++;
        }

        return [implode(' AND ', $clauses), $params];
    }
}
