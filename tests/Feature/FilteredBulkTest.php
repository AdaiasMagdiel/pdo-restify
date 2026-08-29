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

it('updates every row matching a filter, scoped to the policy', function () {
    $response = $this->api->handle(
        new Request('PATCH', '/posts', query: ['title' => 'like.*First*'], body: ['title' => 'Renamed']),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(200);
    expect($response->body)->toHaveCount(1);
    expect($response->body[0]['title'])->toBe('Renamed');

    $others = $this->pdo->query("SELECT title FROM posts WHERE title != 'Renamed'")->fetchAll(PDO::FETCH_COLUMN);
    expect($others)->toContain('Second', 'Other user');
});

it('does not touch rows outside the update policy even if the filter would match them', function () {
    $response = $this->api->handle(
        new Request('PATCH', '/posts', query: ['user_id' => 'eq.2'], body: ['title' => 'Hijacked']),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(200);
    expect($response->body)->toBe([]);

    $title = $this->pdo->query('SELECT title FROM posts WHERE user_id = 2')->fetchColumn();
    expect($title)->toBe('Other user');
});

it('returns an empty list, not a 404, when a filtered update matches nothing', function () {
    $response = $this->api->handle(
        new Request('PATCH', '/posts', query: ['title' => 'eq.Nonexistent'], body: ['title' => 'Renamed']),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(200);
    expect($response->body)->toBe([]);
});

it('excludes an updated row from the response when update is public but select is owner-scoped', function () {
    // The WHERE for the write runs under the *update* policy, but echoing a
    // row back must run under the *select* policy — a permissive update
    // policy must not, via the re-fetch, leak a row the requester couldn't
    // otherwise see.
    $resource = (new Resource('posts'))
        ->columns(['id', 'title', 'body', 'user_id'])
        ->allow(Operation::Select, fn (array $context): array => ['user_id' => $context['user_id']])
        ->allow(Operation::Update); // public — no closure, unrestricted write

    $api = (new Api($this->pdo))->register($resource);

    $response = $api->handle(
        new Request('PATCH', '/posts', query: ['title' => 'like.*Other*'], body: ['title' => 'Renamed']),
        ['user_id' => 1], // does not own the row this filter matches (user_id 2)
    );

    expect($response->status)->toBe(200);
    expect($response->body)->toBe([]);

    $title = $this->pdo->query('SELECT title FROM posts WHERE user_id = 2')->fetchColumn();
    expect($title)->toBe('Renamed');
});

it('rejects combining a filter query string with a bulk (array) body on update', function () {
    $response = $this->api->handle(
        new Request('PATCH', '/posts', query: ['title' => 'eq.First'], body: [
            ['id' => 1, 'title' => 'Updated'],
        ]),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(400);
});

it('deletes every row matching a filter, scoped to the policy', function () {
    $response = $this->api->handle(
        new Request('DELETE', '/posts', query: ['title' => 'like.*Second*']),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(204);

    $count = $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
    expect((int) $count)->toBe(2);
});

it('does not delete rows outside the delete policy even if the filter would match them', function () {
    $response = $this->api->handle(
        new Request('DELETE', '/posts', query: ['user_id' => 'eq.2']),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(204);

    $count = $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
    expect((int) $count)->toBe(3);
});

it('returns 204, not a 404, when a filtered delete matches nothing', function () {
    $response = $this->api->handle(
        new Request('DELETE', '/posts', query: ['title' => 'eq.Nonexistent']),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(204);
});

it('rejects combining a filter query string with a body on delete', function () {
    $response = $this->api->handle(
        new Request('DELETE', '/posts', query: ['title' => 'eq.First'], body: [1]),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(400);
});
