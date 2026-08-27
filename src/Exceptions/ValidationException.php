<?php

declare(strict_types=1);

namespace AdaiasMagdiel\PdoRestify\Exceptions;

/**
 * The request references an unknown column, a malformed filter, or an empty
 * insert/update payload — reported as `422 Unprocessable Entity`.
 */
class ValidationException extends ApiException
{
    public function status(): int
    {
        return 422;
    }
}
