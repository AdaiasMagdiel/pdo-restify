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
    private const OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'like', 'in', 'not_in', 'is_null', 'is_not_null'];

    /** @var string[] Operators that take no value — used as `column=is_null` rather than `column=operator.value`. */
    private const NO_VALUE_OPERATORS = ['is_null', 'is_not_null'];

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

            if (is_string($value) && in_array($value, self::NO_VALUE_OPERATORS, true)) {
                $filters[] = [$column, $value, ''];
                continue;
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
     * Parses an `order=col.dir,col2.dir2` param — comma-separated, each entry
     * being `column` or `column.direction` (direction defaults to `asc`).
     *
     * @param string[] $allowedColumns
     * @return list<array{0: string, 1: string}>|null Null when $order is empty, otherwise a list of [column, direction] pairs.
     * @throws ValidationException if a column is outside the whitelist or a direction isn't `asc`/`desc`.
     */
    public static function order(?string $order, array $allowedColumns): ?array
    {
        if ($order === null || $order === '') {
            return null;
        }

        $result = [];

        foreach (explode(',', $order) as $part) {
            $parts = explode('.', trim($part), 2);
            $column = $parts[0];
            $direction = strtolower($parts[1] ?? 'asc');

            if (!in_array($column, $allowedColumns, true)) {
                throw new ValidationException("Unknown order column: {$column}");
            }

            if (!in_array($direction, ['asc', 'desc'], true)) {
                throw new ValidationException("Unknown order direction: {$direction}");
            }

            $result[] = [$column, $direction];
        }

        return $result;
    }

    /**
     * Resolves a `select=col1,col2,relation(col1,col2)` param against the
     * whitelist, defaulting to every allowed column when no plain column is
     * requested (embeds alone don't replace the flat column list, only add
     * to it).
     *
     * @param string[] $allowedColumns
     * @param string[] $allowedRelations Names of relations declared on the resource
     *                                    (see {@see Resource::relationNames()}).
     * @return array{0: string[], 1: array<string, string[]>} [flat columns, embeds keyed by relation name].
     *         An embed's column list is empty when the caller wrote `relation()` with nothing inside,
     *         meaning "every column that relation's resource allows".
     * @throws ValidationException if a requested column or relation is outside the whitelist,
     *                              or the embed syntax is malformed (unbalanced parentheses, empty
     *                              relation column list).
     */
    public static function select(?string $select, array $allowedColumns, array $allowedRelations = []): array
    {
        if ($select === null || $select === '') {
            return [$allowedColumns, []];
        }

        $columns = [];
        $embeds = [];

        foreach (self::tokenizeSelect($select) as $token) {
            if ($token['columns'] === null) {
                if (!in_array($token['name'], $allowedColumns, true)) {
                    throw new ValidationException("Unknown select column: {$token['name']}");
                }

                $columns[] = $token['name'];

                continue;
            }

            if (!in_array($token['name'], $allowedRelations, true)) {
                throw new ValidationException("Unknown relation: {$token['name']}");
            }

            $embeds[$token['name']] = $token['columns'];
        }

        if ($columns === []) {
            $columns = $allowedColumns;
        }

        return [$columns, $embeds];
    }

    /**
     * Splits a `select=` value into tokens, respecting one level of
     * `relation(...)` nesting so commas inside parentheses don't split the
     * relation's own column list.
     *
     * @return array<int, array{name: string, columns: string[]|null}> `columns` is null for a plain column.
     * @throws ValidationException on unbalanced parentheses or an empty relation column list.
     */
    private static function tokenizeSelect(string $select): array
    {
        $rawTokens = [];
        $buffer = '';
        $depth = 0;

        for ($i = 0, $len = strlen($select); $i < $len; $i++) {
            $char = $select[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth < 0) {
                    throw new ValidationException('Unbalanced parentheses in select');
                }
            }

            if ($char === ',' && $depth === 0) {
                $rawTokens[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        if ($depth !== 0) {
            throw new ValidationException('Unbalanced parentheses in select');
        }

        $rawTokens[] = $buffer;

        $tokens = [];

        foreach ($rawTokens as $rawToken) {
            $rawToken = trim($rawToken);

            if ($rawToken === '') {
                throw new ValidationException('Empty select token');
            }

            if (!preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\((.*)\)$/s', $rawToken, $matches)) {
                $tokens[] = ['name' => $rawToken, 'columns' => null];

                continue;
            }

            $nestedColumns = $matches[2] === '' ? [] : array_map('trim', explode(',', $matches[2]));

            if (in_array('', $nestedColumns, true)) {
                throw new ValidationException("Empty column in relation '{$matches[1]}'");
            }

            $tokens[] = ['name' => $matches[1], 'columns' => $nestedColumns];
        }

        return $tokens;
    }
}
