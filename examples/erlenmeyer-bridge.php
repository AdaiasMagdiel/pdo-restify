<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use AdaiasMagdiel\Erlenmeyer\App;
use AdaiasMagdiel\Erlenmeyer\Request as ErlenmeyerRequest;
use AdaiasMagdiel\Erlenmeyer\Response as ErlenmeyerResponse;
use AdaiasMagdiel\PdoRestify\Api;
use AdaiasMagdiel\PdoRestify\Connection;
use AdaiasMagdiel\PdoRestify\Http\Request;
use AdaiasMagdiel\PdoRestify\Operation;
use AdaiasMagdiel\PdoRestify\Resource;

$pdo = Connection::make('sqlite', __DIR__ . '/database.sqlite');

$posts = (new Resource('posts'))
    ->columns(['id', 'title', 'body', 'user_id']);

$scopedToCurrentUser = fn (array $context): array => ['user_id' => $context['user_id']];

$posts
    ->allow(Operation::Select, $scopedToCurrentUser)
    ->allow(Operation::Insert, $scopedToCurrentUser)
    ->allow(Operation::Update, $scopedToCurrentUser)
    ->allow(Operation::Delete, $scopedToCurrentUser);

$api = (new Api($pdo))->register($posts);

$app = new App();

$dispatch = function (ErlenmeyerRequest $req, ErlenmeyerResponse $res, object $params) use ($api): void {
    $path = trim($params->table ?? '', '/') . (isset($params->id) ? '/' . $params->id : '');

    // In a real app the current user id comes from your authentication layer.
    $context = ['user_id' => 1];

    $request = new Request($req->getMethod(), $path, $req->getQueryParams(), $req->getJson() ?? []);
    $response = $api->handle($request, $context);

    $res->setStatusCode($response->status)->withJson($response->body ?? [])->send();
};

$app->any('/api/[table]', $dispatch);
$app->any('/api/[table]/[id]', $dispatch);

$app->run();
