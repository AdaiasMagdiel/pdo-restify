<?php

use AdaiasMagdiel\PdoRestify\Exceptions\ValidationException;
use AdaiasMagdiel\PdoRestify\Filters;

it('parses valid filters', function () {
    $filters = Filters::parse(['title' => 'like.*hello*', 'user_id' => 'eq.1'], ['title', 'user_id']);

    expect($filters)->toBe([
        ['title', 'like', '*hello*'],
        ['user_id', 'eq', '1'],
    ]);
});

it('ignores reserved query keys', function () {
    $filters = Filters::parse(['select' => 'id,title', 'order' => 'id.asc', 'limit' => '10', 'offset' => '0'], ['id', 'title']);

    expect($filters)->toBe([]);
});

it('rejects filters on columns outside the whitelist', function () {
    Filters::parse(['secret' => 'eq.1'], ['title']);
})->throws(ValidationException::class);

it('rejects filters with an unknown operator', function () {
    Filters::parse(['title' => 'contains.hello'], ['title']);
})->throws(ValidationException::class);

it('rejects filters without an operator', function () {
    Filters::parse(['title' => 'hello'], ['title']);
})->throws(ValidationException::class);

it('parses a valid order clause', function () {
    expect(Filters::order('title.desc', ['title']))->toBe(['title', 'desc']);
    expect(Filters::order('title', ['title']))->toBe(['title', 'asc']);
    expect(Filters::order(null, ['title']))->toBeNull();
});

it('rejects an order column outside the whitelist', function () {
    Filters::order('secret.asc', ['title']);
})->throws(ValidationException::class);

it('resolves selected columns against the whitelist', function () {
    expect(Filters::select('id,title', ['id', 'title', 'body']))->toBe(['id', 'title']);
    expect(Filters::select(null, ['id', 'title']))->toBe(['id', 'title']);
});

it('rejects a select column outside the whitelist', function () {
    Filters::select('id,secret', ['id', 'title']);
})->throws(ValidationException::class);
