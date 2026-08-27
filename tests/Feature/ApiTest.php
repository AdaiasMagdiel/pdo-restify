<?php

use AdaiasMagdiel\PdoRestify\Api;
use AdaiasMagdiel\PdoRestify\Http\Request;
use AdaiasMagdiel\PdoRestify\Operation;
use AdaiasMagdiel\PdoRestify\Resource;

beforeEach(function () {
    $this->pdo = sqliteConnection();
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

it('filters by query string operators', function () {
    $response = $this->api->handle(new Request('GET', '/posts', ['title' => 'eq.First']), ['user_id' => 1]);

    expect($response->body)->toHaveCount(1);
    expect($response->body[0]['title'])->toBe('First');
});

it('finds a single resource by id', function () {
    $response = $this->api->handle(new Request('GET', '/posts/1'), ['user_id' => 1]);

    expect($response->status)->toBe(200);
    expect($response->body['title'])->toBe('First');
});

it('returns 404 when the id belongs to another user', function () {
    $response = $this->api->handle(new Request('GET', '/posts/3'), ['user_id' => 1]);

    expect($response->status)->toBe(404);
});

it('inserts a row and forces the policy conditions over client input', function () {
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
    // Regression guard: PDO's UPDATE rowCount() is driver-dependent (MySQL
    // reports rows changed, not rows matched — see Api::update()), so this
    // must not be decided by rowCount(). SQLite alone can't prove the fix;
    // see the matching test in tests/Integration/CrudTest.php.
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

it('returns 404 deleting a row owned by another user', function () {
    $response = $this->api->handle(new Request('DELETE', '/posts/3'), ['user_id' => 1]);

    expect($response->status)->toBe(404);

    $count = $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
    expect((int) $count)->toBe(3);
});

it('rejects an insert with an empty body', function () {
    // Uses an unscoped insert policy: ownedPostsApi()'s policy always forces
    // a user_id condition into the insert, so $data would never be empty.
    $resource = (new Resource('posts'))
        ->columns(['id', 'title', 'body', 'user_id'])
        ->allow(Operation::Insert);

    $api = (new Api($this->pdo))->register($resource);

    $response = $api->handle(new Request('POST', '/posts', body: []), []);

    expect($response->status)->toBe(422);
});

it('rejects an update with nothing to change', function () {
    $response = $this->api->handle(new Request('PATCH', '/posts/1', body: []), ['user_id' => 1]);

    expect($response->status)->toBe(422);
});

it('rejects unknown columns in the request body', function () {
    $response = $this->api->handle(
        new Request('POST', '/posts', body: ['title' => 'New', 'body' => 'x', 'user_id' => 1, 'is_admin' => 1]),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(422);
});

it('rejects filters on non-whitelisted columns', function () {
    $response = $this->api->handle(new Request('GET', '/posts', ['secret' => 'eq.1']), ['user_id' => 1]);

    expect($response->status)->toBe(422);
});

it('denies operations with no policy registered', function () {
    $resource = (new Resource('posts'))->columns(['id', 'title', 'body', 'user_id']);
    $api = (new Api($this->pdo))->register($resource);

    $response = $api->handle(new Request('GET', '/posts'), []);

    expect($response->status)->toBe(403);
});

it('returns 404 for unregistered resources', function () {
    $response = $this->api->handle(new Request('GET', '/comments'), ['user_id' => 1]);

    expect($response->status)->toBe(404);
});

it('exposes every row when a resource opts out of row-level scoping', function () {
    $resource = (new Resource('posts'))
        ->columns(['id', 'title', 'body', 'user_id'])
        ->allow(Operation::Select);

    $api = (new Api($this->pdo))->register($resource);

    $response = $api->handle(new Request('GET', '/posts'), []);

    expect($response->status)->toBe(200);
    expect($response->body)->toHaveCount(3);
});
