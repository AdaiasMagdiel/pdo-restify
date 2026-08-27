<?php

use AdaiasMagdiel\PdoRestify\Http\Request;

beforeEach(function () {
    $this->pdo = sqliteConnection();
    $this->pdo->exec("INSERT INTO posts (title, body, user_id) VALUES ('First', 'Body 1', 1)");
    $this->pdo->exec("INSERT INTO posts (title, body, user_id) VALUES ('Second', 'Body 2', 1)");
    $this->pdo->exec("INSERT INTO posts (title, body, user_id) VALUES ('Other user', 'Body 3', 2)");

    $this->api = ownedPostsApi($this->pdo);
});

it('bulk inserts every row, forcing the policy conditions on each one', function () {
    $response = $this->api->handle(
        new Request('POST', '/posts', body: [
            ['title' => 'A', 'body' => 'Body A', 'user_id' => 999],
            ['title' => 'B', 'body' => 'Body B', 'user_id' => 999],
        ]),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(200);
    expect($response->body)->toHaveCount(2);
    // Checks each returned row is correlated to the row that produced it
    // (not, say, two copies of the same lastInsertId() due to a loop bug).
    expect($response->body[0]['title'])->toBe('A');
    expect($response->body[0]['user_id'])->toBe(1);
    expect($response->body[1]['title'])->toBe('B');
    expect($response->body[1]['user_id'])->toBe(1);
    expect($response->body[0]['id'])->not->toBe($response->body[1]['id']);

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

it('bulk updates every row identified by its primary key, scoped to the policy', function () {
    $response = $this->api->handle(
        new Request('PATCH', '/posts', body: [
            ['id' => 1, 'title' => 'First updated'],
            ['id' => 2, 'title' => 'Second updated'],
        ]),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(200);
    expect($response->body)->toHaveCount(2);
    expect($response->body[0]['title'])->toBe('First updated');
    expect($response->body[1]['title'])->toBe('Second updated');
});

it('rolls back the entire bulk update if any row is outside the policy', function () {
    $response = $this->api->handle(
        new Request('PATCH', '/posts', body: [
            ['id' => 1, 'title' => 'First updated'],
            ['id' => 3, 'title' => 'Hijacked'],
        ]),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(404);

    $title = $this->pdo->query('SELECT title FROM posts WHERE id = 1')->fetchColumn();
    expect($title)->toBe('First');
});

it('rejects a bulk update row missing the primary key', function () {
    $response = $this->api->handle(
        new Request('PATCH', '/posts', body: [
            ['title' => 'No id here'],
        ]),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(422);
});

it('still requires an id for a non-bulk PATCH with no id', function () {
    $response = $this->api->handle(
        new Request('PATCH', '/posts', body: ['title' => 'Missing id']),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(400);
});
