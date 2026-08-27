<?php

declare(strict_types=1);

namespace AdaiasMagdiel\PdoRestify\Http;

/**
 * Framework-agnostic result of {@see \AdaiasMagdiel\PdoRestify\Api::handle()}.
 *
 * `$body` is plain data (array, scalar or null), not an encoded string — it's
 * up to the host application to serialize it (typically `json_encode`) and
 * write it out along with `$status` and `$headers`.
 */
final class Response
{
    /**
     * @param int $status HTTP status code.
     * @param mixed $body Response payload, ready to be encoded by the caller.
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly mixed $body,
        public readonly array $headers = ['Content-Type' => 'application/json'],
    ) {
    }

    /**
     * Shorthand for the common case: a JSON body with the default headers.
     *
     * @param array<string, string> $extraHeaders Merged on top of the default Content-Type header.
     */
    public static function json(mixed $body, int $status = 200, array $extraHeaders = []): self
    {
        return new self($status, $body, ['Content-Type' => 'application/json'] + $extraHeaders);
    }
}
