<?php

declare(strict_types=1);

namespace AdaiasMagdiel\PdoRestify;

/**
 * The two relationship shapes a {@see Resource} can declare via
 * {@see Resource::hasMany()} / {@see Resource::belongsTo()}.
 */
enum RelationType: string
{
    case HasMany = 'hasMany';
    case BelongsTo = 'belongsTo';
}
