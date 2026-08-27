<?php

declare(strict_types=1);

namespace AdaiasMagdiel\PdoRestify\Exceptions;

/**
 * The requested operation has no policy registered on the resource —
 * reported as `403 Forbidden`.
 */
class ForbiddenException extends ApiException
{
    public function status(): int
    {
        return 403;
    }
}
