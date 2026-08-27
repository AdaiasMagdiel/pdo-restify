<?php

use AdaiasMagdiel\PdoRestify\Api;
use AdaiasMagdiel\PdoRestify\Http\Request;
use AdaiasMagdiel\PdoRestify\Operation;
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
    // clause, never re-applied them to the SET data — so a caller could
    // update a row they legitimately own while smuggling a different
    // user_id into the same request body, silently transferring ownership
    // (or, with a tenant_id-scoped policy, injecting a row into another
    // tenant's data). Policy-owned columns must always win, exactly like
    // insert already enforces.
    $response = $this->api->handle(
        new Request('PATCH', '/posts/1', body: ['title' => 'Still mine?', 'user_id' => 999]),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(200);
    expect($response->body['title'])->toBe('Still mine?');
    expect($response->body['user_id'])->toBe(1);

    $row = $this->pdo->query('SELECT user_id FROM posts WHERE id = 1')->fetch();
    expect((int) $row['user_id'])->toBe(1);
});

it('cannot reassign ownership through a bulk update either', function () {
    $response = $this->api->handle(
        new Request('PATCH', '/posts', body: [
            ['id' => 1, 'title' => 'Still mine?', 'user_id' => 999],
        ]),
        ['user_id' => 1],
    );

    expect($response->status)->toBe(200);
    expect($response->body[0]['user_id'])->toBe(1);
});

it('still rejects an update with nothing but the primary key', function () {
    // The fix must not weaken this: "nothing to update" is decided from
    // what the *client* sent, before policy conditions are merged back in —
    // otherwise a scoped policy would make every empty PATCH "succeed".
    $response = $this->api->handle(new Request('PATCH', '/posts/1', body: []), ['user_id' => 1]);

    expect($response->status)->toBe(422);
});

it('rejects a table/column/condition identifier that is not a safe SQL identifier', function () {
    // Defense in depth found in audit: QueryBuilder trusted every caller to
    // pre-validate identifiers, but a Resource policy closure's condition
    // *keys* were never checked by anything — only Filters (for request-
    // derived column names) enforced a whitelist. A policy closure is
    // ordinary application code, not request input, so this requires an
    // unusual mistake to reach in practice — but QueryBuilder now refuses
    // to build a query from an invalid identifier regardless of who sent it.
    $resource = (new Resource('posts'))
        ->columns(['id', 'title', 'body', 'user_id'])
        ->allow(Operation::Select, fn (array $context): array => ['id; DROP TABLE posts; --' => 1]);

    $api = (new Api($this->pdo))->register($resource);

    $api->handle(new Request('GET', '/posts'), []);
})->throws(InvalidArgumentException::class);
