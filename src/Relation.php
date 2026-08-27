<?php

declare(strict_types=1);

namespace AdaiasMagdiel\PdoRestify;

/**
 * A relationship declared on a {@see Resource} via {@see Resource::hasMany()}
 * or {@see Resource::belongsTo()}, resolved and embedded by {@see Api} when
 * requested through `select=relationName(...)`.
 */
final class Relation
{
    public function __construct(
        public readonly RelationType $type,
        public readonly string $table,
        public readonly string $foreignKey,
    ) {
    }
}
