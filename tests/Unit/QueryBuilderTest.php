<?php

use AdaiasMagdiel\PdoRestify\QueryBuilder;

it('builds a select query with filters, conditions and pagination', function () {
    [$sql, $params] = QueryBuilder::select(
        'posts',
        ['id', 'title'],
        [['title', 'like', '*hello*']],
        ['user_id' => 1],
        [['id', 'desc']],
        10,
        5,
    );

    expect($sql)->toBe('SELECT id, title FROM posts WHERE user_id = :c0 AND title LIKE :f1 ORDER BY id DESC LIMIT 10 OFFSET 5');
    expect($params)->toBe([':c0' => 1, ':f1' => '%hello%']);
});

it('builds a select query with multi-column order', function () {
    [$sql] = QueryBuilder::select('posts', ['id', 'title'], [], [], [['created_at', 'desc'], ['title', 'asc']], null, 0);

    expect($sql)->toBe('SELECT id, title FROM posts ORDER BY created_at DESC, title ASC');
});

it('builds a count query with filters and conditions', function () {
    [$sql, $params] = QueryBuilder::count('posts', [['user_id', 'eq', '1']], ['tenant_id' => 5]);

    expect($sql)->toBe('SELECT COUNT(*) AS total FROM posts WHERE tenant_id = :c0 AND user_id = :f1');
    expect($params)->toBe([':c0' => 5, ':f1' => '1']);
});

it('builds a not_in clause', function () {
    [$sql, $params] = QueryBuilder::select('posts', ['id'], [['id', 'not_in', '1,2,3']], [], null, 50, 0);

    expect($sql)->toBe('SELECT id FROM posts WHERE id NOT IN (:f0_0, :f0_1, :f0_2) LIMIT 50 OFFSET 0');
    expect($params)->toBe([':f0_0' => '1', ':f0_1' => '2', ':f0_2' => '3']);
});

it('builds an is_null clause', function () {
    [$sql, $params] = QueryBuilder::select('posts', ['id'], [['subtitle', 'is_null', '']], [], null, 50, 0);

    expect($sql)->toBe('SELECT id FROM posts WHERE subtitle IS NULL LIMIT 50 OFFSET 0');
    expect($params)->toBe([]);
});

it('builds an is_not_null clause', function () {
    [$sql, $params] = QueryBuilder::select('posts', ['id'], [['subtitle', 'is_not_null', '']], [], null, 50, 0);

    expect($sql)->toBe('SELECT id FROM posts WHERE subtitle IS NOT NULL LIMIT 50 OFFSET 0');
    expect($params)->toBe([]);
});

it('builds an in clause with one placeholder per value', function () {
    [$sql, $params] = QueryBuilder::select('posts', ['id'], [['id', 'in', '1,2,3']], [], null, 50, 0);

    expect($sql)->toBe('SELECT id FROM posts WHERE id IN (:f0_0, :f0_1, :f0_2) LIMIT 50 OFFSET 0');
    expect($params)->toBe([':f0_0' => '1', ':f0_1' => '2', ':f0_2' => '3']);
});

it('omits the LIMIT/OFFSET clause when limit is null', function () {
    [$sql] = QueryBuilder::select('posts', ['id'], [], [], null, null, 0);

    expect($sql)->toBe('SELECT id FROM posts');
});

it('builds an insert query', function () {
    [$sql, $params] = QueryBuilder::insert('posts', ['title' => 'Hello', 'user_id' => 1]);

    expect($sql)->toBe('INSERT INTO posts (title, user_id) VALUES (:i_title, :i_user_id)');
    expect($params)->toBe([':i_title' => 'Hello', ':i_user_id' => 1]);
});

it('builds an update query scoped by conditions', function () {
    [$sql, $params] = QueryBuilder::update('posts', ['title' => 'Hello'], ['id' => 1, 'user_id' => 2]);

    expect($sql)->toBe('UPDATE posts SET title = :s_title WHERE id = :c0 AND user_id = :c1');
    expect($params)->toBe([':s_title' => 'Hello', ':c0' => 1, ':c1' => 2]);
});

it('builds a delete query scoped by conditions', function () {
    [$sql, $params] = QueryBuilder::delete('posts', ['id' => 1, 'user_id' => 2]);

    expect($sql)->toBe('DELETE FROM posts WHERE id = :c0 AND user_id = :c1');
    expect($params)->toBe([':c0' => 1, ':c1' => 2]);
});

it('rejects an unsafe condition key in select, even though callers are expected to validate first', function () {
    QueryBuilder::select('posts', ['id'], [], ['id = 1; --' => 1], null, 10, 0);
})->throws(InvalidArgumentException::class);

it('rejects an unsafe column key in insert data', function () {
    QueryBuilder::insert('posts', ['title; DROP TABLE posts; --' => 'x']);
})->throws(InvalidArgumentException::class);

it('rejects an unsafe condition key in update', function () {
    QueryBuilder::update('posts', ['title' => 'x'], ['1=1; --' => 1]);
})->throws(InvalidArgumentException::class);

it('rejects an unsafe condition key in delete', function () {
    QueryBuilder::delete('posts', ['1=1; --' => 1]);
})->throws(InvalidArgumentException::class);

it('rejects an unsafe table name', function () {
    QueryBuilder::select('posts; DROP TABLE posts; --', ['id'], [], [], null, 10, 0);
})->throws(InvalidArgumentException::class);
