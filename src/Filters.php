<?php

declare(strict_types=1);

namespace AdaiasMagdiel\PdoRestify;

use AdaiasMagdiel\PdoRestify\Exceptions\ValidationException;

/**
 * Parses the REST-ish query string conventions (`column=operator.value`,
 * `select=`, `order=`) into structured data, validating every column
 * against a resource's whitelist along the way.
 */
final class Filters
{
    /** @var string[] Filter operators accepted in `column=operator.value`. */
    private const OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'like', 'in'];

    /** @var string[] Query params that are never treated as column filters. */
    private const RESERVED = ['select', 'order', 'limit', 'offset'];

    /**
     * Extracts `column=operator.value` filters from the query string.
     *
     * @param array<string, mixed> $query
     * @param string[] $allowedColumns
     * @return array<int, array{0: string, 1: string, 2: string}> Tuples of [column, operator, rawValue].
     * @throws ValidationException if a filter targets a column outside the whitelist,
     *                              is missing the `operator.` prefix, or uses an unknown operator.
     */
    public static function parse(array $query, array $allowedColumns): array
    {
        $filters = [];

        foreach ($query as $column => $value) {
            if (in_array($column, self::RESERVED, true)) {
                continue;
            }

            if (!in_array($column, $allowedColumns, true)) {
                throw new ValidationException("Unknown filter column: {$column}");
            }

            if (!is_string($value) || !str_contains($value, '.')) {
                throw new ValidationException("Invalid filter for '{$column}', expected 'operator.value'");
            }

            [$operator, $raw] = explode('.', $value, 2);

            if (!in_array($operator, self::OPERATORS, true)) {
                throw new ValidationException("Unknown filter operator '{$operator}' for '{$column}'");
            }

            $filters[] = [$column, $operator, $raw];
        }

        return $filters;
    }

    /**
     * Parses an `order=column.direction` param (direction defaults to `asc`).
     *
     * @param string[] $allowedColumns
     * @return array{0: string, 1: string}|null Null when $order is empty, otherwise [column, direction].
     * @throws ValidationException if the column is outside the whitelist or the direction isn't `asc`/`desc`.
     */
    public static function order(?string $order, array $allowedColumns): ?array
    {
        if ($order === null || $order === '') {
            return null;
        }

        $parts = explode('.', $order, 2);
        $column = $parts[0];
        $direction = strtolower($parts[1] ?? 'asc');

        if (!in_array($column, $allowedColumns, true)) {
            throw new ValidationException("Unknown order column: {$column}");
        }

        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new ValidationException("Unknown order direction: {$direction}");
        }

        return [$column, $direction];
    }

    /**
     * Resolves a `select=col1,col2` param against the whitelist, defaulting
     * to every allowed column when none is given.
     *
     * @param string[] $allowedColumns
     * @return string[]
     * @throws ValidationException if a requested column is outside the whitelist.
     */
    public static function select(?string $select, array $allowedColumns): array
    {
        if ($select === null || $select === '') {
            return $allowedColumns;
        }

        $columns = array_map('trim', explode(',', $select));

        foreach ($columns as $column) {
            if (!in_array($column, $allowedColumns, true)) {
                throw new ValidationException("Unknown select column: {$column}");
            }
        }

        return $columns;
    }
}
