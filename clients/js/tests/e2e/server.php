<?php

declare(strict_types=1);

/**
 * Boots a real pdo-restify Api over PHP's built-in dev server, for the JS
 * client's end-to-end tests to hit with real fetch() calls — not a mock of
 * the wire protocol, the actual PHP library in this repo. Run via:
 *
 *   php -S 127.0.0.1:PORT server.php
 *
 * with PDO_RESTIFY_E2E_DB pointing to a SQLite file (the built-in server
 * runs each request as a fresh script, so state must live on disk, not in
 * :memory:). See ../e2e.test.ts for how it's spawned.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use AdaiasMagdiel\PdoRestify\Api;
use AdaiasMagdiel\PdoRestify\Connection;
use AdaiasMagdiel\PdoRestify\Exceptions\ForbiddenException;
use AdaiasMagdiel\PdoRestify\Http\Request;
use AdaiasMagdiel\PdoRestify\Operation;
use AdaiasMagdiel\PdoRestify\Resource;

$dbFile = getenv('PDO_RESTIFY_E2E_DB');
if ($dbFile === false) {
    http_response_code(500);
    echo json_encode(['error' => 'PDO_RESTIFY_E2E_DB is not set']);
    exit;
}

$isFreshDatabase = !file_exists($dbFile);

$pdo = Connection::make('sqlite', $dbFile);

if ($isFreshDatabase) {
    $pdo->exec('
        CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            user_id INTEGER NOT NULL
        )
    ');
    $pdo->exec('
        CREATE TABLE comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            body TEXT NOT NULL
        )
    ');
}

$scopedToCurrentUser = function (array $context): array {
    if (!isset($context['user_id'])) {
        throw new ForbiddenException('Authentication required (send an X-User-Id header)');
    }

    return ['user_id' => $context['user_id']];
};

$posts = (new Resource('posts'))
    ->columns(['id', 'title', 'body', 'user_id'])
    ->allow(Operation::Select, $scopedToCurrentUser)
    ->allow(Operation::Insert, $scopedToCurrentUser)
    ->allow(Operation::Update, $scopedToCurrentUser)
    ->allow(Operation::Delete, $scopedToCurrentUser)
    ->hasMany('comments', foreignKey: 'post_id');

$comments = (new Resource('comments'))
    ->columns(['id', 'post_id', 'body'])
    ->allow(Operation::Select)
    ->allow(Operation::Insert) // open — comments aren't user-scoped in this fixture
    ->belongsTo('post', foreignKey: 'post_id', table: 'posts');

$api = (new Api($pdo))->register($posts)->register($comments);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$body = json_decode(file_get_contents('php://input') ?: '', true) ?? [];

$request = new Request($_SERVER['REQUEST_METHOD'], $path, $_GET, is_array($body) ? $body : []);

$userId = $_SERVER['HTTP_X_USER_ID'] ?? null;
$context = $userId !== null ? ['user_id' => (int) $userId] : [];

$response = $api->handle($request, $context);

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header("{$name}: {$value}");
}
echo json_encode($response->body);
