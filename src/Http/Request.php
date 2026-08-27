<?php

declare(strict_types=1);

namespace AdaiasMagdiel\PdoRestify\Http;

/**
 * Framework-agnostic representation of an inbound request, as consumed by
 * {@see \AdaiasMagdiel\PdoRestify\Api::handle()}.
 *
 * pdo-restify never reads superglobals or a framework's request object
 * directly — the host application builds one of these from whatever it
 * already has, which is what keeps the library pluggable anywhere.
 */
final class Request
{
    /**
     * @param string $method HTTP verb, e.g. `GET`, `POST`, `PATCH`, `DELETE`.
     * @param string $path Resource path, e.g. `/posts` or `/posts/1` (any framework mount prefix already stripped).
     * @param array<string, string> $query Query string parameters, as sent by the client (unparsed filter syntax).
     * @param array<string, mixed> $body Request body, already decoded (e.g. from JSON) into an associative array.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
    ) {
    }
}
