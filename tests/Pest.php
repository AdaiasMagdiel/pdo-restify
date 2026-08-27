<?php

use AdaiasMagdiel\PdoRestify\Api;
use AdaiasMagdiel\PdoRestify\Operation;
use AdaiasMagdiel\PdoRestify\Resource;

uses(Tests\TestCase::class)->in('Unit', 'Feature', 'Integration');

/**
 * Creates an in-memory SQLite connection seeded with a `posts` table,
 * used across the test suite as the reference driver.
 */
function sqliteConnection(): PDO
{
    $pdo = new PDO('sqlite::memory:', options: [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('
        CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            user_id INTEGER NOT NULL,
            subtitle TEXT
        )
    ');

    return $pdo;
}

/**
 * Builds an Api with a `posts` resource where every operation is scoped
 * to the `user_id` passed in the request context — our RLS-like policy.
 */
function ownedPostsApi(PDO $pdo): Api
{
    $resource = (new Resource('posts'))
        ->columns(['id', 'title', 'body', 'user_id']);

    $scopedToUser = fn (array $context): array => ['user_id' => $context['user_id']];

    $resource
        ->allow(Operation::Select, $scopedToUser)
        ->allow(Operation::Insert, $scopedToUser)
        ->allow(Operation::Update, $scopedToUser)
        ->allow(Operation::Delete, $scopedToUser);

    return (new Api($pdo))->register($resource);
}
