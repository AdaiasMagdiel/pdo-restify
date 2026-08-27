<?php

use AdaiasMagdiel\PdoRestify\Api;
use AdaiasMagdiel\PdoRestify\Http\Request;
use AdaiasMagdiel\PdoRestify\Operation;
use AdaiasMagdiel\PdoRestify\Resource;

beforeEach(function () {
    $this->pdo = sqliteConnection();
    $this->pdo->exec('
        CREATE TABLE comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            body TEXT NOT NULL
        )
    ');

    $this->pdo->exec("INSERT INTO posts (title, body, user_id) VALUES ('First', 'Body 1', 1)");
    $this->pdo->exec("INSERT INTO posts (title, body, user_id) VALUES ('Second', 'Body 2', 1)");

    $this->pdo->exec("INSERT INTO comments (post_id, body) VALUES (1, 'Comment 1a')");
    $this->pdo->exec("INSERT INTO comments (post_id, body) VALUES (1, 'Comment 1b')");
    $this->pdo->exec("INSERT INTO comments (post_id, body) VALUES (2, 'Comment 2a')");

    $posts = (new Resource('posts'))
        ->columns(['id', 'title', 'body', 'user_id'])
        ->allow(Operation::Select)
        ->hasMany('comments', foreignKey: 'post_id');

    $comments = (new Resource('comments'))
        ->columns(['id', 'post_id', 'body'])
        ->allow(Operation::Select)
        ->belongsTo('post', foreignKey: 'post_id', table: 'posts');

    $this->api = (new Api($this->pdo))->register($posts)->register($comments);
});

it('embeds a hasMany relation on list', function () {
    $response = $this->api->handle(
        new Request('GET', '/posts', ['select' => 'id,title,comments(id,body)', 'order' => 'id.asc']),
        [],
    );

    expect($response->status)->toBe(200);
    expect($response->body[0]['comments'])->toHaveCount(2);
    expect($response->body[0]['comments'][0])->toBe(['id' => 1, 'body' => 'Comment 1a']);
    expect($response->body[1]['comments'])->toHaveCount(1);
});

it('embeds a hasMany relation on find', function () {
    $response = $this->api->handle(new Request('GET', '/posts/1', ['select' => 'id,comments(body)']), []);

    expect($response->status)->toBe(200);
    expect($response->body['comments'])->toHaveCount(2);
    expect($response->body['comments'][0])->toBe(['body' => 'Comment 1a']);
});

it('embeds a hasMany relation even when the select= list omits the parent primary key', function () {
    // Regression guard: the parent's own primary key must still be fetched
    // internally to group the related rows by, even though the caller only
    // asked for `title` — and must not leak into the response since they
    // didn't ask for it.
    $response = $this->api->handle(new Request('GET', '/posts/1', ['select' => 'title,comments(body)']), []);

    expect($response->status)->toBe(200);
    expect($response->body)->toBe([
        'title' => 'First',
        'comments' => [
            ['body' => 'Comment 1a'],
            ['body' => 'Comment 1b'],
        ],
    ]);
});

it('embeds every related column when the relation parentheses are empty', function () {
    $response = $this->api->handle(new Request('GET', '/posts/1', ['select' => 'id,comments()']), []);

    expect($response->body['comments'][0])->toBe(['id' => 1, 'post_id' => 1, 'body' => 'Comment 1a']);
});

it('embeds a belongsTo relation as a single object', function () {
    $response = $this->api->handle(new Request('GET', '/comments/1', ['select' => 'id,body,post(id,title)']), []);

    expect($response->status)->toBe(200);
    expect($response->body['post'])->toBe(['id' => 1, 'title' => 'First']);
});

it('returns null for a belongsTo relation with no match', function () {
    $this->pdo->exec("INSERT INTO comments (post_id, body) VALUES (999, 'Orphan')");

    $response = $this->api->handle(new Request('GET', '/comments/4', ['select' => 'id,post(id)']), []);

    expect($response->status)->toBe(200);
    expect($response->body['post'])->toBeNull();
});

it('returns an empty array for a hasMany relation with no matches', function () {
    $this->pdo->exec("INSERT INTO posts (title, body, user_id) VALUES ('No comments', 'x', 1)");

    $response = $this->api->handle(new Request('GET', '/posts/3', ['select' => 'id,comments(id)']), []);

    expect($response->status)->toBe(200);
    expect($response->body['comments'])->toBe([]);
});

it('scopes an embedded relation through its own select policy', function () {
    $posts = (new Resource('posts'))
        ->columns(['id', 'title', 'body', 'user_id'])
        ->allow(Operation::Select)
        ->hasMany('comments', foreignKey: 'post_id');

    $scopedComments = (new Resource('comments'))
        ->columns(['id', 'post_id', 'body'])
        ->allow(Operation::Select, fn (array $context): array => ['id' => 1]); // only comment #1, ever

    $api = (new Api($this->pdo))->register($posts)->register($scopedComments);

    $response = $api->handle(new Request('GET', '/posts/1', ['select' => 'id,comments(id)']), []);

    expect($response->body['comments'])->toHaveCount(1);
    expect($response->body['comments'][0]['id'])->toBe(1);
});

it('rejects an unknown relation in select', function () {
    $response = $this->api->handle(new Request('GET', '/posts', ['select' => 'id,unknown(id)']), []);

    expect($response->status)->toBe(422);
});

it("rejects an embed column outside the related resource's whitelist", function () {
    $response = $this->api->handle(new Request('GET', '/posts/1', ['select' => 'id,comments(secret)']), []);

    expect($response->status)->toBe(422);
});

it('returns 403 when the related resource has no select policy', function () {
    $posts = (new Resource('posts'))
        ->columns(['id', 'title', 'body', 'user_id'])
        ->allow(Operation::Select)
        ->hasMany('comments', foreignKey: 'post_id');

    $closedComments = (new Resource('comments'))->columns(['id', 'post_id', 'body']);

    $api = (new Api($this->pdo))->register($posts)->register($closedComments);

    $response = $api->handle(new Request('GET', '/posts/1', ['select' => 'id,comments(id)']), []);

    expect($response->status)->toBe(403);
});

it('throws when a relation points to a table that was never registered', function () {
    $posts = (new Resource('posts'))
        ->columns(['id', 'title', 'body', 'user_id'])
        ->allow(Operation::Select)
        ->hasMany('comments', foreignKey: 'post_id');

    $api = (new Api($this->pdo))->register($posts); // comments never registered

    $api->handle(new Request('GET', '/posts/1', ['select' => 'id,comments(id)']), []);
})->throws(LogicException::class);
