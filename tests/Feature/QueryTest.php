<?php

use AdaiasMagdiel\PdoRestify\Api;
use AdaiasMagdiel\PdoRestify\Http\Request;
use AdaiasMagdiel\PdoRestify\Operation;
use AdaiasMagdiel\PdoRestify\Resource;

beforeEach(function () {
    $this->pdo = sqliteConnection();
    $this->pdo->exec("INSERT INTO posts (title, body, user_id) VALUES ('First', 'Body 1', 1)");
    $this->pdo->exec("INSERT INTO posts (title, body, user_id) VALUES ('Second', 'Body 2', 1)");
    $this->pdo->exec("INSERT INTO posts (title, body, user_id) VALUES ('Third', 'Body 3', 1)");

    $resource = (new Resource('posts'))
        ->columns(['id', 'title', 'body', 'user_id'])
        ->allow(Operation::Select);

    $this->api = (new Api($this->pdo))->register($resource);

    // A separate, tightly-capped Api makes limit-clamping observable with only 3 rows.
    $clampedResource = (new Resource('posts'))
        ->columns(['id', 'title', 'body', 'user_id'])
        ->allow(Operation::Select);

    $this->clampedApi = (new Api($this->pdo, maxLimit: 2))->register($clampedResource);
});

it('actually sorts rows by the requested column and direction', function () {
    $response = $this->api->handle(new Request('GET', '/posts', ['order' => 'id.desc']), []);

    expect(array_column($response->body, 'id'))->toBe([3, 2, 1]);
});

it('clamps limit to the configured maximum instead of erroring', function () {
    $response = $this->clampedApi->handle(new Request('GET', '/posts', ['limit' => '1000']), []);

    expect($response->status)->toBe(200);
    expect($response->body)->toHaveCount(2); // clamped to maxLimit: 2, not the 3 available rows
});

it('clamps a negative offset to zero instead of erroring', function () {
    $response = $this->api->handle(new Request('GET', '/posts', ['offset' => '-5', 'order' => 'id.asc']), []);

    expect($response->status)->toBe(200);
    expect($response->body[0]['id'])->toBe(1);
});

it('applies the gt operator against real data, not just against the generated SQL', function () {
    $response = $this->api->handle(new Request('GET', '/posts', ['id' => 'gt.1']), []);

    expect(array_column($response->body, 'id'))->toBe([2, 3]);
});

it('applies the ne operator against real data', function () {
    $response = $this->api->handle(new Request('GET', '/posts', ['title' => 'ne.Second', 'order' => 'id.asc']), []);

    expect(array_column($response->body, 'id'))->toBe([1, 3]);
});

it('applies the lt operator against real data', function () {
    $response = $this->api->handle(new Request('GET', '/posts', ['id' => 'lt.3', 'order' => 'id.asc']), []);

    expect(array_column($response->body, 'id'))->toBe([1, 2]);
});

it('applies the gte operator against real data', function () {
    $response = $this->api->handle(new Request('GET', '/posts', ['id' => 'gte.2', 'order' => 'id.asc']), []);

    expect(array_column($response->body, 'id'))->toBe([2, 3]);
});

it('applies the lte operator against real data', function () {
    $response = $this->api->handle(new Request('GET', '/posts', ['id' => 'lte.2', 'order' => 'id.asc']), []);

    expect(array_column($response->body, 'id'))->toBe([1, 2]);
});

it('applies the in operator against real data', function () {
    $response = $this->api->handle(new Request('GET', '/posts', ['id' => 'in.1,3', 'order' => 'id.asc']), []);

    expect(array_column($response->body, 'id'))->toBe([1, 3]);
});

it('restricts the returned columns to what select= asks for', function () {
    $response = $this->api->handle(new Request('GET', '/posts', ['select' => 'id,title']), []);

    expect(array_keys($response->body[0]))->toBe(['id', 'title']);
});

it('applies the not_in operator against real data', function () {
    $response = $this->api->handle(new Request('GET', '/posts', ['id' => 'not_in.1,3', 'order' => 'id.asc']), []);

    expect(array_column($response->body, 'id'))->toBe([2]);
});

it('applies multi-column ordering', function () {
    $response = $this->api->handle(new Request('GET', '/posts', ['order' => 'user_id.asc,id.desc']), []);

    $ids = array_column($response->body, 'id');
    expect($ids)->toBe([3, 2, 1]);
});

it('includes X-Total-Count and pagination headers in the list response', function () {
    $response = $this->api->handle(new Request('GET', '/posts', ['limit' => '2', 'offset' => '0']), []);

    expect($response->headers['X-Total-Count'])->toBe('3');
    expect($response->headers['X-Page-Limit'])->toBe('2');
    expect($response->headers['X-Page-Offset'])->toBe('0');
    expect($response->body)->toHaveCount(2);
});

it('applies the is_null operator against real data', function () {
    $this->pdo->exec("INSERT INTO posts (title, body, user_id, subtitle) VALUES ('With subtitle', 'Body', 1, 'sub')");

    $resource = (new Resource('posts'))
        ->columns(['id', 'title', 'body', 'user_id', 'subtitle'])
        ->allow(Operation::Select);

    $api = (new Api($this->pdo))->register($resource);
    $response = $api->handle(new Request('GET', '/posts', ['subtitle' => 'is_null', 'order' => 'id.asc']), []);

    expect($response->status)->toBe(200);
    foreach ($response->body as $row) {
        expect($row['subtitle'])->toBeNull();
    }
});

it('applies the is_not_null operator against real data', function () {
    $this->pdo->exec("INSERT INTO posts (title, body, user_id, subtitle) VALUES ('With subtitle', 'Body', 1, 'sub')");

    $resource = (new Resource('posts'))
        ->columns(['id', 'title', 'body', 'user_id', 'subtitle'])
        ->allow(Operation::Select);

    $api = (new Api($this->pdo))->register($resource);
    $response = $api->handle(new Request('GET', '/posts', ['subtitle' => 'is_not_null']), []);

    expect($response->status)->toBe(200);
    expect($response->body)->toHaveCount(1);
    expect($response->body[0]['subtitle'])->toBe('sub');
});
