<?php

use AdaiasMagdiel\PdoRestify\Api;
use AdaiasMagdiel\PdoRestify\Http\Request;
use AdaiasMagdiel\PdoRestify\Operation;
use AdaiasMagdiel\PdoRestify\RawCondition;
use AdaiasMagdiel\PdoRestify\Resource;

/**
 * Regression tests for issues found during a manual security audit — kept
 * separate from ApiTest.php so the exploit scenario each one closes stays
 * easy to find later.
 */
beforeEach(function () {
    $this->pdo = sqliteConnection();
    $this->pdo->exec("INSERT INTO posts (title, body, user_id) VALUES ('Mine', 'Body', 1)");

    $this->api = ownedPostsApi($this->pdo);
});

it('cannot reassign a policy-scoped column to another owner via update', function () {
    // Found in audit: update() only used policy conditions for the WHERE
    // clause, never re-checked them against the written row — so a caller
    // could update a row they legitimately own while smuggling a different
    // user_id into the same request body, silently transferring ownership
    // (or, with a tenant_id-scoped policy, injecting a row into another
    // tenant's data). Now the policy condition is re-checked as a WITH CHECK
    // after the write (see Api::applyUpdate()): a row that no longer
    // satisfies it rolls the whole update back instead of silently keeping
    // the old value.
    $response = $this->api->handle(
        new Request('PATCH', '/posts/1', body: ['title' => 'Still mine?', 'user_id' => 999]),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(403);

    $row = $this->pdo->query('SELECT title, user_id FROM posts WHERE id = 1')->fetch();
    expect($row['title'])->toBe('Mine');
    expect((int) $row['user_id'])->toBe(1);
});

it('cannot reassign ownership through a bulk update either', function () {
    $response = $this->api->handle(
        new Request('PATCH', '/posts', body: [
            ['id' => 1, 'title' => 'Still mine?', 'user_id' => 999],
        ]),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(403);

    $row = $this->pdo->query('SELECT title, user_id FROM posts WHERE id = 1')->fetch();
    expect($row['title'])->toBe('Mine');
    expect((int) $row['user_id'])->toBe(1);
});

it('still rejects an update with nothing but the primary key', function () {
    // The fix must not weaken this: "nothing to update" is decided from
    // what the *client* sent, before policy conditions are merged back in —
    // otherwise a scoped policy would make every empty PATCH "succeed".
    $response = $this->api->handle(new Request('PATCH', '/posts/1', body: []), ['user_id' => 1]);

    expect($response->status)->toBe(422);
});

it('returns 404, not a misleading 200, when update is denied but select is public', function () {
    // Found in audit: when select is public but update/delete is owner-scoped,
    // a denied update ran its WHERE under the update policy (0 rows affected,
    // uncheckable via rowCount() across drivers) and then re-fetched via
    // find(), which uses the *select* policy — public, so it always found the
    // row and returned 200 with the unchanged data instead of 404.
    $resource = (new Resource('posts'))
        ->columns(['id', 'title', 'body', 'user_id'])
        ->allow(Operation::Select) // public — no closure, unrestricted read
        ->allow(Operation::Update, fn (array $context): RawCondition => new RawCondition('user_id = :uid', [':uid' => $context['user_id']]));

    $api = (new Api($this->pdo))->register($resource);

    $response = $api->handle(
        new Request('PATCH', '/posts/1', body: ['title' => 'Hijacked']),
        ['user_id' => 999], // not the owner (post 1 belongs to user_id 1)
    );

    expect($response->status)->toBe(404);

    $row = $this->pdo->query('SELECT title FROM posts WHERE id = 1')->fetch();
    expect($row['title'])->toBe('Mine');
});

it('rejects an unsafe table/column identifier from request-derived input', function () {
    // Defense in depth: QueryBuilder trusted every caller to pre-validate
    // identifiers, but only Filters (for request-derived column names)
    // enforced a whitelist elsewhere — a table/column name reaching
    // QueryBuilder is always re-checked regardless of who sent it. Since
    // Resource::allow() policies moved to RawCondition (a raw SQL boolean
    // expression, deliberately trusted verbatim — see its docblock), there is
    // no longer a "condition key" for this to apply to; only $table/$columns
    // and $filters (from Filters::parse(), already whitelist-checked) remain
    // identifier-checked inputs, which is what this now exercises via a
    // still-invalid $table.
    $resource = new Resource('posts; DROP TABLE posts; --');

    $resource->columns(['id']);
})->throws(InvalidArgumentException::class);
