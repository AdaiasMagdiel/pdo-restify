<?php

declare(strict_types=1);

namespace AdaiasMagdiel\PdoRestify\Exceptions;

/**
 * Base for every exception {@see \AdaiasMagdiel\PdoRestify\Api::handle()}
 * catches and turns into an error {@see \AdaiasMagdiel\PdoRestify\Http\Response},
 * using {@see self::status()} as the HTTP status code.
 */
abstract class ApiException extends \RuntimeException
{
    /**
     * HTTP status code this exception should be reported as.
     */
    abstract public function status(): int;
}
