<?php

use AdaiasMagdiel\PdoRestify\Exceptions\ForbiddenException;
use AdaiasMagdiel\PdoRestify\Resource;

it('rejects invalid table identifiers', function () {
    new Resource('posts; DROP TABLE users');
})->throws(InvalidArgumentException::class);

it('rejects invalid column identifiers', function () {
    (new Resource('posts'))->columns(['id', 'title, updated_at = 1']);
})->throws(InvalidArgumentException::class);

it('rejects unknown operations', function () {
    (new Resource('posts'))->allow('truncate', fn () => []);
})->throws(InvalidArgumentException::class);

it('denies an operation with no policy by default', function () {
    (new Resource('posts'))->policyFor('select');
})->throws(ForbiddenException::class);

it('returns the registered policy for an operation', function () {
    $policy = fn (array $context) => ['user_id' => $context['user_id']];

    $resource = (new Resource('posts'))->allow('select', $policy);

    expect($resource->policyFor('select'))->toBe($policy);
});

it('allows an operation with no scoping when no policy is given', function () {
    $resource = (new Resource('posts'))->allow('select');

    expect(($resource->policyFor('select'))([]))->toBe([]);
});
