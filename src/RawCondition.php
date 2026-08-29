<?php

declare(strict_types=1);

namespace AdaiasMagdiel\PdoRestify;

/**
 * A raw SQL boolean expression plus its bound parameters — how a
 * {@see Resource} policy scopes rows (see {@see Resource::allow()}).
 *
 * Unlike the old `column => value` condition maps, this is not validated or
 * interpreted by pdo-restify at all: $sql is trusted verbatim and merged
 * directly into the query. That's deliberate — a policy is application code
 * registered by the integrating app, not client input, so pdo-restify treats
 * it the same way it already treats table/column names it's told to use.
 * The caller is responsible for never concatenating unsanitized input into
 * $sql; every dynamic value must be bound through $params instead.
 */
final class RawCondition
{
    /** @param array<string, mixed> $params Named parameters referenced in $sql (e.g. `:auth_id`). */
    public function __construct(
        public readonly string $sql,
        public readonly array $params = [],
    ) {
    }
}
