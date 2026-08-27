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

it('rejects a bulk insert row that is not an object', function () {
    $response = $this->api->handle(
        new Request('POST', '/posts', body: [
            ['title' => 'A', 'body' => 'Body A', 'user_id' => 1],
            'not an object',
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

it('bulk deletes every row identified by its primary key, scoped to the policy', function () {
    $response = $this->api->handle(
        new Request('DELETE', '/posts', body: [1, 2]),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(204);

    $count = $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
    expect((int) $count)->toBe(1);
});

it('rolls back the entire bulk delete if any id is outside the policy', function () {
    $response = $this->api->handle(
        new Request('DELETE', '/posts', body: [1, 3]),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(404);

    $count = $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
    expect((int) $count)->toBe(3);
});

it('rejects a bulk delete id that is not a scalar', function () {
    $response = $this->api->handle(
        new Request('DELETE', '/posts', body: [1, ['id' => 2]]),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(422);

    $count = $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
    expect((int) $count)->toBe(3);
});

it('still requires an id for a non-bulk DELETE with no id', function () {
    $response = $this->api->handle(new Request('DELETE', '/posts'), ['user_id' => 1]);

    expect($response->status)->toBe(400);
});
