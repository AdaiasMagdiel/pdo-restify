<?php

use AdaiasMagdiel\PdoRestify\Api;
use AdaiasMagdiel\PdoRestify\Connection;
use AdaiasMagdiel\PdoRestify\Http\Request;
use AdaiasMagdiel\PdoRestify\Operation;
use AdaiasMagdiel\PdoRestify\Resource;

/**
 * Exercises hasMany/belongsTo embedding (tests/Feature/RelationsTest.php)
 * against a real MySQL/MariaDB server — the embed mechanism runs a second,
 * `IN (...)`-filtered query per relation, which is worth proving works the
 * same way it does against SQLite. See tests/Integration/CrudTest.php for
 * how PDO_RESTIFY_TEST_DRIVER and friends are configured.
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

    $this->pdo->exec('DROP TABLE IF EXISTS comments');
    $this->pdo->exec('DROP TABLE IF EXISTS posts');
    $this->pdo->exec('
        CREATE TABLE posts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            user_id INT NOT NULL
        )
    ');
    $this->pdo->exec('
        CREATE TABLE comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            body TEXT NOT NULL
        )
    ');

    $this->pdo->exec("INSERT INTO posts (title, body, user_id) VALUES ('First', 'Body 1', 1)");
    $this->pdo->exec("INSERT INTO posts (title, body, user_id) VALUES ('Second', 'Body 2', 1)");
    $this->pdo->exec('INSERT INTO comments (post_id, body) VALUES (1, "Comment 1a")');
    $this->pdo->exec('INSERT INTO comments (post_id, body) VALUES (1, "Comment 1b")');
    $this->pdo->exec('INSERT INTO comments (post_id, body) VALUES (2, "Comment 2a")');

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
    expect($response->body[1]['comments'])->toHaveCount(1);
});

it('embeds a belongsTo relation as a single object', function () {
    $response = $this->api->handle(new Request('GET', '/comments/1', ['select' => 'id,body,post(id,title)']), []);

    expect($response->status)->toBe(200);
    expect($response->body['post'])->toBe(['id' => 1, 'title' => 'First']);
});
