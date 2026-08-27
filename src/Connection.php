<?php

declare(strict_types=1);

namespace AdaiasMagdiel\PdoRestify;

use PDO;

/**
 * Convenience factory for the PDO connections pdo-restify supports.
 *
 * Using it is entirely optional: {@see Api} and {@see Resource} only need a
 * plain PDO instance, so you can build your own connection however your
 * application already does it and pass that in instead.
 */
final class Connection
{
    /** @var string[] Drivers this library is tested against and willing to configure. */
    private const DRIVERS = ['mysql', 'mariadb', 'sqlite'];

    /**
     * Builds a PDO instance with sane defaults (exception error mode,
     * associative fetch, real prepared statements).
     *
     * @param string $driver One of `mysql`, `mariadb`, `sqlite`.
     * @param string $database Database name for mysql/mariadb, or the file path
     *                         (or `:memory:`) for sqlite.
     * @param array<int, mixed> $options Extra PDO driver options, merged under the
     *                                    library's defaults so they can still be overridden.
     * @throws \InvalidArgumentException if $driver is not supported.
     */
    public static function make(
        string $driver,
        string $database,
        ?string $host = null,
        ?int $port = null,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
    ): PDO {
        if (!in_array($driver, self::DRIVERS, true)) {
            throw new \InvalidArgumentException("Unsupported driver: {$driver}");
        }

        $dsn = match ($driver) {
            'sqlite' => "sqlite:{$database}",
            'mysql', 'mariadb' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $host ?? '127.0.0.1',
                $port ?? 3306,
                $database,
            ),
        };

        return new PDO($dsn, $username, $password, $options + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
