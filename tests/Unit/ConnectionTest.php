<?php

use AdaiasMagdiel\PdoRestify\Connection;

it('creates a sqlite connection', function () {
    $pdo = Connection::make('sqlite', ':memory:');

    expect($pdo)->toBeInstanceOf(PDO::class);
    expect($pdo->getAttribute(PDO::ATTR_ERRMODE))->toBe(PDO::ERRMODE_EXCEPTION);
});

it('rejects unsupported drivers', function () {
    Connection::make('postgres', 'db');
})->throws(InvalidArgumentException::class);
