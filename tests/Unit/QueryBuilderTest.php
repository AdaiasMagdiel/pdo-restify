<?php

use AdaiasMagdiel\PdoRestify\QueryBuilder;
use AdaiasMagdiel\PdoRestify\RawCondition;

it('builds a select query with filters, a condition and pagination', function () {
    [$sql, $params] = QueryBuilder::select(
        'posts',
        ['id', 'title'],
        [['title', 'like', '*hello*']],
        new RawCondition('user_id = :uid', [':uid' => 1]),
        [['id', 'desc']],
        10,
        5,
    );

    expect($sql)->toBe('SELECT id, title FROM posts WHERE (user_id = :uid) AND title LIKE :f0 ORDER BY id DESC LIMIT 10 OFFSET 5');
    expect($params)->toBe([':uid' => 1, ':f0' => '%hello%']);
});

it('builds a select query with multi-column order', function () {
    [$sql] = QueryBuilder::select('posts', ['id', 'title'], [], null, [['created_at', 'desc'], ['title', 'asc']], null, 0);

    expect($sql)->toBe('SELECT id, title FROM posts ORDER BY created_at DESC, title ASC');
});

it('builds a count query with filters and a condition', function () {
    [$sql, $params] = QueryBuilder::count('posts', [['user_id', 'eq', '1']], new RawCondition('tenant_id = :tid', [':tid' => 5]));

    expect($sql)->toBe('SELECT COUNT(*) AS total FROM posts WHERE (tenant_id = :tid) AND user_id = :f0');
    expect($params)->toBe([':tid' => 5, ':f0' => '1']);
});

it('builds a select query with no condition and no filters', function () {
    [$sql, $params] = QueryBuilder::select('posts', ['id'], [], null, null, 10, 0);

    expect($sql)->toBe('SELECT id FROM posts LIMIT 10 OFFSET 0');
    expect($params)->toBe([]);
});

it('builds a not_in clause', function () {
    [$sql, $params] = QueryBuilder::select('posts', ['id'], [['id', 'not_in', '1,2,3']], null, null, 50, 0);

    expect($sql)->toBe('SELECT id FROM posts WHERE id NOT IN (:f0_0, :f0_1, :f0_2) LIMIT 50 OFFSET 0');
    expect($params)->toBe([':f0_0' => '1', ':f0_1' => '2', ':f0_2' => '3']);
});

it('builds an is_null clause', function () {
    [$sql, $params] = QueryBuilder::select('posts', ['id'], [['subtitle', 'is_null', '']], null, null, 50, 0);

    expect($sql)->toBe('SELECT id FROM posts WHERE subtitle IS NULL LIMIT 50 OFFSET 0');
    expect($params)->toBe([]);
});

it('builds an is_not_null clause', function () {
    [$sql, $params] = QueryBuilder::select('posts', ['id'], [['subtitle', 'is_not_null', '']], null, null, 50, 0);

    expect($sql)->toBe('SELECT id FROM posts WHERE subtitle IS NOT NULL LIMIT 50 OFFSET 0');
    expect($params)->toBe([]);
});

it('builds an in clause with one placeholder per value', function () {
    [$sql, $params] = QueryBuilder::select('posts', ['id'], [['id', 'in', '1,2,3']], null, null, 50, 0);

    expect($sql)->toBe('SELECT id FROM posts WHERE id IN (:f0_0, :f0_1, :f0_2) LIMIT 50 OFFSET 0');
    expect($params)->toBe([':f0_0' => '1', ':f0_1' => '2', ':f0_2' => '3']);
});

it('omits the LIMIT/OFFSET clause when limit is null', function () {
    [$sql] = QueryBuilder::select('posts', ['id'], [], null, null, null, 0);

    expect($sql)->toBe('SELECT id FROM posts');
});

it('builds an insert query', function () {
    [$sql, $params] = QueryBuilder::insert('posts', ['title' => 'Hello', 'user_id' => 1]);

    expect($sql)->toBe('INSERT INTO posts (title, user_id) VALUES (:i_title, :i_user_id)');
    expect($params)->toBe([':i_title' => 'Hello', ':i_user_id' => 1]);
});

it('builds an update query scoped by a condition', function () {
    [$sql, $params] = QueryBuilder::update('posts', ['title' => 'Hello'], new RawCondition('id = :id AND user_id = :uid', [':id' => 1, ':uid' => 2]));

    expect($sql)->toBe('UPDATE posts SET title = :s_title WHERE (id = :id AND user_id = :uid)');
    expect($params)->toBe([':s_title' => 'Hello', ':id' => 1, ':uid' => 2]);
});

it('builds an update query with no condition', function () {
    [$sql, $params] = QueryBuilder::update('posts', ['title' => 'Hello'], null);

    expect($sql)->toBe('UPDATE posts SET title = :s_title');
    expect($params)->toBe([':s_title' => 'Hello']);
});

it('builds a delete query scoped by a condition', function () {
    [$sql, $params] = QueryBuilder::delete('posts', new RawCondition('id = :id AND user_id = :uid', [':id' => 1, ':uid' => 2]));

    expect($sql)->toBe('DELETE FROM posts WHERE (id = :id AND user_id = :uid)');
    expect($params)->toBe([':id' => 1, ':uid' => 2]);
});

it('builds a delete query with no condition', function () {
    [$sql, $params] = QueryBuilder::delete('posts', null);

    expect($sql)->toBe('DELETE FROM posts');
    expect($params)->toBe([]);
});

it('rejects an unsafe column key in insert data', function () {
    QueryBuilder::insert('posts', ['title; DROP TABLE posts; --' => 'x']);
})->throws(InvalidArgumentException::class);

it('rejects an unsafe table name', function () {
    QueryBuilder::select('posts; DROP TABLE posts; --', ['id'], [], null, null, 10, 0);
})->throws(InvalidArgumentException::class);

it('a RawCondition\'s sql is trusted verbatim, unlike identifiers', function () {
    // Unlike the old column => value maps, a RawCondition's $sql is not an
    // identifier pdo-restify validates — it's a boolean expression the
    // registering application is fully responsible for. This is deliberate:
    // see RawCondition's docblock.
    [$sql] = QueryBuilder::select('posts', ['id'], [], new RawCondition('1=1; -- not a real injection here, just proving it is not rejected'), null, 10, 0);

    expect($sql)->toContain('1=1; -- not a real injection here, just proving it is not rejected');
});
