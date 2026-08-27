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
    expect(Filters::order('title.desc', ['title']))->toBe([['title', 'desc']]);
    expect(Filters::order('title', ['title']))->toBe([['title', 'asc']]);
    expect(Filters::order(null, ['title']))->toBeNull();
});

it('parses a multi-column order clause', function () {
    $result = Filters::order('created_at.desc,title.asc', ['created_at', 'title']);

    expect($result)->toBe([['created_at', 'desc'], ['title', 'asc']]);
});

it('parses is_null as a no-value operator', function () {
    $filters = Filters::parse(['subtitle' => 'is_null'], ['subtitle']);

    expect($filters)->toBe([['subtitle', 'is_null', '']]);
});

it('parses is_not_null as a no-value operator', function () {
    $filters = Filters::parse(['subtitle' => 'is_not_null'], ['subtitle']);

    expect($filters)->toBe([['subtitle', 'is_not_null', '']]);
});

it('parses not_in as a regular operator with a value', function () {
    $filters = Filters::parse(['id' => 'not_in.1,2,3'], ['id']);

    expect($filters)->toBe([['id', 'not_in', '1,2,3']]);
});

it('rejects an order column outside the whitelist', function () {
    Filters::order('secret.asc', ['title']);
})->throws(ValidationException::class);

it('rejects an unknown order direction', function () {
    Filters::order('title.sideways', ['title']);
})->throws(ValidationException::class);

it('resolves selected columns against the whitelist', function () {
    expect(Filters::select('id,title', ['id', 'title', 'body']))->toBe([['id', 'title'], []]);
    expect(Filters::select(null, ['id', 'title']))->toBe([['id', 'title'], []]);
});

it('rejects a select column outside the whitelist', function () {
    Filters::select('id,secret', ['id', 'title']);
})->throws(ValidationException::class);

it('parses relation embeds inside select', function () {
    [$columns, $embeds] = Filters::select('id,comments(id,body)', ['id', 'title'], ['comments']);

    expect($columns)->toBe(['id']);
    expect($embeds)->toBe(['comments' => ['id', 'body']]);
});

it('defaults an embed with empty parentheses to every allowed column on the relation', function () {
    [, $embeds] = Filters::select('comments()', ['id', 'title'], ['comments']);

    expect($embeds)->toBe(['comments' => []]);
});

it('defaults the flat column list to everything when only an embed is requested', function () {
    [$columns] = Filters::select('comments(id)', ['id', 'title'], ['comments']);

    expect($columns)->toBe(['id', 'title']);
});

it('rejects an embed for a relation that was not declared', function () {
    Filters::select('comments(id)', ['id', 'title'], []);
})->throws(ValidationException::class);

it('rejects an empty token from a stray comma in select', function () {
    Filters::select('id,,title', ['id', 'title'], []);
})->throws(ValidationException::class);

it('rejects an empty column from a stray comma inside a relation', function () {
    Filters::select('comments(id,,body)', ['id'], ['comments']);
})->throws(ValidationException::class);

it('rejects unbalanced parentheses in select', function () {
    Filters::select('comments(id', ['id'], ['comments']);
})->throws(ValidationException::class);

it('rejects a stray closing parenthesis in select', function () {
    Filters::select('id)', ['id'], []);
})->throws(ValidationException::class);
