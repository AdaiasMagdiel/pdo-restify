<?php

declare(strict_types=1);

namespace AdaiasMagdiel\PdoRestify\Exceptions;

/**
 * The request is malformed at the routing level — e.g. an unsupported HTTP
 * method, or a PATCH/DELETE with no id — reported as `400 Bad Request`.
 */
class BadRequestException extends ApiException
{
    public function status(): int
    {
        return 400;
    }
}
