<?php

declare(strict_types=1);

namespace AdaiasMagdiel\PdoRestify;

/**
 * The CRUD operations a {@see Resource} can expose through {@see Resource::allow()}.
 */
enum Operation: string
{
    case Select = 'select';
    case Insert = 'insert';
    case Update = 'update';
    case Delete = 'delete';
}
