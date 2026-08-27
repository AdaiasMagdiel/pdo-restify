<?php

use AdaiasMagdiel\PdoRestify\Exceptions\ForbiddenException;
use AdaiasMagdiel\PdoRestify\Operation;
use AdaiasMagdiel\PdoRestify\Relation;
use AdaiasMagdiel\PdoRestify\RelationType;
use AdaiasMagdiel\PdoRestify\Resource;

it('rejects invalid table identifiers', function () {
    new Resource('posts; DROP TABLE users');
})->throws(InvalidArgumentException::class);

it('rejects invalid column identifiers', function () {
    (new Resource('posts'))->columns(['id', 'title, updated_at = 1']);
})->throws(InvalidArgumentException::class);

it('rejects operations that are not a valid Operation case', function () {
    (new Resource('posts'))->allow('truncate', fn () => []);
})->throws(TypeError::class);

it('denies an operation with no policy by default', function () {
    (new Resource('posts'))->policyFor(Operation::Select);
})->throws(ForbiddenException::class);

it('returns the registered policy for an operation', function () {
    $policy = fn (array $context) => ['user_id' => $context['user_id']];

    $resource = (new Resource('posts'))->allow(Operation::Select, $policy);

    expect($resource->policyFor(Operation::Select))->toBe($policy);
});

it('allows an operation with no scoping when no policy is given', function () {
    $resource = (new Resource('posts'))->allow(Operation::Select);

    expect(($resource->policyFor(Operation::Select))([]))->toBe([]);
});

it('declares a hasMany relation, defaulting the table to the relation name', function () {
    $resource = (new Resource('posts'))->hasMany('comments', foreignKey: 'post_id');

    $relation = $resource->relation('comments');

    expect($relation)->toBeInstanceOf(Relation::class);
    expect($relation->type)->toBe(RelationType::HasMany);
    expect($relation->table)->toBe('comments');
    expect($relation->foreignKey)->toBe('post_id');
    expect($resource->relationNames())->toBe(['comments']);
});

it('declares a belongsTo relation with an explicit table name', function () {
    $resource = (new Resource('comments'))->belongsTo('post', foreignKey: 'post_id', table: 'posts');

    $relation = $resource->relation('post');

    expect($relation->type)->toBe(RelationType::BelongsTo);
    expect($relation->table)->toBe('posts');
    expect($relation->foreignKey)->toBe('post_id');
});

it('returns null for an undeclared relation', function () {
    expect((new Resource('posts'))->relation('comments'))->toBeNull();
});

it('rejects an invalid identifier in a relation declaration', function () {
    (new Resource('posts'))->hasMany('comments', foreignKey: 'post_id; DROP TABLE users');
})->throws(InvalidArgumentException::class);
