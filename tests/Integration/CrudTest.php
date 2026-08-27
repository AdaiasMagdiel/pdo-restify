<?php

use AdaiasMagdiel\PdoRestify\Connection;
use AdaiasMagdiel\PdoRestify\Http\Request;

/**
 * Exercises the same CRUD/policy behavior tests/Feature/ApiTest.php checks
 * against SQLite, but against a real MySQL or MariaDB server, to catch any
 * driver-specific SQL syntax or binding differences QueryBuilder might hit.
 *
 * Runs only when PDO_RESTIFY_TEST_DRIVER is set — deliberately not part of
 * the default local test run, since spinning up MySQL/MariaDB locally is
 * unnecessary overhead for day-to-day development. The GitHub Actions
 * "Integration" workflow sets it against real service containers.
 */
beforeEach(function () {
    $driver = getenv('PDO_RESTIFY_TEST_DRIVER');

    if ($driver === false) {
        $this->markTestSkipped(
            'Set PDO_RESTIFY_TEST_DRIVER (and _HOST/_PORT/_DATABASE/_USERNAME/_PASSWORD) '
            . 'to run integration tests against a real MySQL/MariaDB server.',
        );
    }

    $this->pdo = Connection::make(
        driver: $driver,
        database: getenv('PDO_RESTIFY_TEST_DATABASE'),
        host: getenv('PDO_RESTIFY_TEST_HOST'),
        port: (int) getenv('PDO_RESTIFY_TEST_PORT'),
        username: getenv('PDO_RESTIFY_TEST_USERNAME'),
        password: getenv('PDO_RESTIFY_TEST_PASSWORD'),
    );

    $this->pdo->exec('DROP TABLE IF EXISTS posts');
    $this->pdo->exec('
        CREATE TABLE posts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            user_id INT NOT NULL
        )
    ');

    $this->pdo->exec("INSERT INTO posts (title, body, user_id) VALUES ('First', 'Body 1', 1)");
    $this->pdo->exec("INSERT INTO posts (title, body, user_id) VALUES ('Second', 'Body 2', 1)");
    $this->pdo->exec("INSERT INTO posts (title, body, user_id) VALUES ('Other user', 'Body 3', 2)");

    $this->api = ownedPostsApi($this->pdo);
});

it('lists only rows matching the policy', function () {
    $response = $this->api->handle(new Request('GET', '/posts'), ['user_id' => 1]);

    expect($response->status)->toBe(200);
    expect($response->body)->toHaveCount(2);
});

it('filters, orders and paginates through the real driver', function () {
    $response = $this->api->handle(
        new Request('GET', '/posts', ['title' => 'like.*ir*', 'order' => 'id.desc', 'limit' => '1']),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(200);
    expect($response->body)->toHaveCount(1);
    expect($response->body[0]['title'])->toBe('First');
});

it('finds a single resource by id', function () {
    $response = $this->api->handle(new Request('GET', '/posts/1'), ['user_id' => 1]);

    expect($response->status)->toBe(200);
    expect($response->body['title'])->toBe('First');
});

it('inserts a row, forcing the policy conditions over client input', function () {
    $response = $this->api->handle(
        new Request('POST', '/posts', body: ['title' => 'New', 'body' => 'New body', 'user_id' => 999]),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(200);
    expect($response->body['user_id'])->toBe(1);
});

it('updates a row scoped to the policy', function () {
    $response = $this->api->handle(
        new Request('PATCH', '/posts/1', body: ['title' => 'Updated']),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(200);
    expect($response->body['title'])->toBe('Updated');
});

it('refuses to update a row owned by another user', function () {
    $response = $this->api->handle(
        new Request('PATCH', '/posts/3', body: ['title' => 'Hijacked']),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(404);
});

it('succeeds updating a row to the value it already has', function () {
    // MySQL/MariaDB's default PDO rowCount() for UPDATE reports rows
    // *changed*, not rows *matched* — unlike SQLite. A no-op update (new
    // value equals the current one) reports 0 there. This is the test that
    // actually exercises that driver difference; see Api::update().
    $response = $this->api->handle(
        new Request('PATCH', '/posts/1', body: ['title' => 'First']),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(200);
    expect($response->body['title'])->toBe('First');
});

it('deletes a row scoped to the policy', function () {
    $response = $this->api->handle(new Request('DELETE', '/posts/1'), ['user_id' => 1]);

    expect($response->status)->toBe(204);

    $count = $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
    expect((int) $count)->toBe(2);
});

it('bulk inserts every row within a transaction', function () {
    $response = $this->api->handle(
        new Request('POST', '/posts', body: [
            ['title' => 'A', 'body' => 'Body A', 'user_id' => 999],
            ['title' => 'B', 'body' => 'Body B', 'user_id' => 999],
        ]),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(200);
    expect($response->body)->toHaveCount(2);
    expect($response->body[0]['user_id'])->toBe(1);

    $count = $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
    expect((int) $count)->toBe(5);
});

it('rolls back the entire bulk insert if any row is invalid', function () {
    $response = $this->api->handle(
        new Request('POST', '/posts', body: [
            ['title' => 'A', 'body' => 'Body A', 'user_id' => 1],
            ['title' => 'B', 'body' => 'Body B', 'is_admin' => 1],
        ]),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(422);

    $count = $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
    expect((int) $count)->toBe(3);
});

it('bulk updates every row identified by its primary key', function () {
    $response = $this->api->handle(
        new Request('PATCH', '/posts', body: [
            ['id' => 1, 'title' => 'First updated'],
            ['id' => 2, 'title' => 'Second updated'],
        ]),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(200);
    expect($response->body)->toHaveCount(2);
});
