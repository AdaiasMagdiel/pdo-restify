<?php

declare(strict_types=1);

namespace AdaiasMagdiel\PdoRestify\Exceptions;

/**
 * No row matched the requested id (and, where applicable, the resource's
 * policy conditions) — reported as `404 Not Found`.
 */
class NotFoundException extends ApiException
{
    public function status(): int
    {
        return 404;
    }
}
