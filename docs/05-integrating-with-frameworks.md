# Integrating with a framework

`Api::handle()` takes and returns plain data — a `Http\Request` in, a
`Http\Response` out. It never touches `$_SERVER`, `php://input`,
`header()`, or `echo`. That's what makes it pluggable into anything: the
integration work is always the same three steps, regardless of framework.

1. Build a `Request` from whatever request object your framework hands you.
2. Call `$api->handle($request, $context)`.
3. Write the returned `Response` back out using your framework's normal
   response mechanism.

## The general shape

```php
function bridge(Api $api, /* your framework's request */ $incoming, array $context): /* your framework's response */
{
    $request = new Request(
        method: $incoming->method(),
        path: $incoming->path(),       // strip any mount prefix first, e.g. /api
        query: $incoming->query(),
        body: $incoming->jsonBody() ?? [],
    );

    $response = $api->handle($request, $context);

    return /* build your framework's response from */ $response->status, $response->body, $response->headers;
}
```

The `path` you pass in must already be relative to wherever you mounted the
API — if your router serves this under `/api/*`, strip the `/api` prefix
before constructing the `Request`; `Api::handle()` expects `/posts` or
`/posts/1`, not `/api/posts`.

## Example: Erlenmeyer

[`examples/erlenmeyer-bridge.php`](../examples/erlenmeyer-bridge.php) is a
complete, runnable version of this using
[`adaiasmagdiel/erlenmeyer`](https://github.com/adaiasmagdiel/erlenmeyer).
The relevant part:

```php
use AdaiasMagdiel\Erlenmeyer\App;
use AdaiasMagdiel\Erlenmeyer\Request as ErlenmeyerRequest;
use AdaiasMagdiel\Erlenmeyer\Response as ErlenmeyerResponse;
use AdaiasMagdiel\PdoRestify\Http\Request;

$dispatch = function (ErlenmeyerRequest $req, ErlenmeyerResponse $res, object $params) use ($api): void {
    $path = trim($params->table ?? '', '/') . (isset($params->id) ? '/' . $params->id : '');

    $context = ['user_id' => 1]; // from your auth layer

    $request = new Request($req->getMethod(), $path, $req->getQueryParams(), $req->getJson() ?? []);
    $response = $api->handle($request, $context);

    $res->setStatusCode($response->status)->withJson($response->body ?? [])->send();
};

$app->any('/api/[table]', $dispatch);
$app->any('/api/[table]/[id]', $dispatch);
```

Erlenmeyer's `[table]`/`[id]` bracket segments already give us the path in
pieces, so there's no prefix-stripping to do here — the bridge just
reassembles them into the `{table}` or `{table}/{id}` shape `Api::handle()`
expects.

## Example: a plain script (no framework)

```php
$request = new Request(
    method: $_SERVER['REQUEST_METHOD'],
    path: parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    query: $_GET,
    body: json_decode(file_get_contents('php://input'), true) ?? [],
);

$response = $api->handle($request, ['user_id' => $_SESSION['user_id'] ?? null]);

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header("{$name}: {$value}");
}
echo json_encode($response->body);
```

## Example: Slim

```php
$app->any('/posts[/{id}]', function (
    Psr\Http\Message\ServerRequestInterface $slimRequest,
    Psr\Http\Message\ResponseInterface $slimResponse,
    array $args,
) use ($api) {
    $path = 'posts' . (isset($args['id']) ? '/' . $args['id'] : '');
    $body = json_decode((string) $slimRequest->getBody(), true) ?? [];

    $request = new Request($slimRequest->getMethod(), $path, $slimRequest->getQueryParams(), $body);
    $response = $api->handle($request, ['user_id' => /* from PSR-7 auth middleware attribute */ null]);

    $slimResponse->getBody()->write(json_encode($response->body));

    return $slimResponse
        ->withStatus($response->status)
        ->withHeader('Content-Type', 'application/json');
});
```

## Example: Laravel

```php
Route::any('/posts/{id?}', function (Illuminate\Http\Request $laravelRequest, ?string $id = null) use ($api) {
    $path = 'posts' . ($id !== null ? "/{$id}" : '');

    $request = new Request($laravelRequest->method(), $path, $laravelRequest->query(), $laravelRequest->json()->all());
    $response = $api->handle($request, ['user_id' => auth()->id()]);

    return response()->json($response->body, $response->status, $response->headers);
});
```

None of these are meant to be copy-pasted verbatim into a production app —
routing one URL per resource, or mapping every id-bearing path onto a single
handler, is a choice your own router setup will shape. What matters is the
three-step pattern: build a `Request`, call `handle()`, write out the
`Response`.
