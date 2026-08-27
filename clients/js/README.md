# @adaiasmagdiel/pdo-restify

A typed JS/TS client for [pdo-restify](https://github.com/adaiasmagdiel/pdo-restify) —
the PHP library this package lives alongside. It's a thin wrapper around
`fetch`: it builds the `column=operator.value` query strings and paths
pdo-restify expects, and normalizes the response into a predictable
`{ data, error, status }` shape. No dependencies, works in browsers, Node
20+, and edge runtimes (anywhere with a global `fetch`).

> Early, minimal first version, matching the PHP library's own scope — see
> its [README](../../README.md#roadmap) for what's planned beyond this.

## Install

```bash
npm install @adaiasmagdiel/pdo-restify
```

Don't use npm/TypeScript/a bundler at all? Drop this in a plain `<script>`
tag — no build step, no install, no toolchain:

```html
<script src="https://cdn.jsdelivr.net/npm/@adaiasmagdiel/pdo-restify/dist/index.global.js"></script>
<script>
  const api = PdoRestify.createClient('https://api.example.com/');

  api.from('posts').select().then(({ data, error }) => {
    console.log(data);
  });
</script>
```

That's the exact same client, just built as a plain global (`window.PdoRestify`)
instead of an ES module — served straight off the npm package via
[jsDelivr](https://www.jsdelivr.com/) (unpkg.com works the same way, if you
prefer it). Every method shown below works identically; only the `import`
line differs.

## Quick start

```ts
import { createClient } from '@adaiasmagdiel/pdo-restify';

const api = createClient('https://api.example.com/', {
  headers: { Authorization: `Bearer ${token}` },
});

interface Post {
  id: number;
  title: string;
  body: string;
  user_id: number;
}

const { data, error } = await api
  .from<Post>('posts')
  .select('id,title')
  .eq('user_id', 42)
  .order('id', 'desc')
  .limit(10);

if (error) {
  console.error(error.message, error.status);
} else {
  console.log(data); // Post[] (only id/title, per select())
}
```

Every request builder is **thenable** — `await`ing it is what actually sends
the request. Nothing fires until then, and awaiting the same builder twice
only sends one request (the result is cached).

## API

### `createClient(baseUrl, options?)`

`baseUrl` is where pdo-restify is mounted, e.g. `'https://api.example.com/'`
or `'https://api.example.com/v1/'` if it's behind a prefix — a trailing
slash is added if you leave it off.

`options`:

- `headers` — a plain object, or a function (sync or async) returning one,
  called fresh before every request. Use the function form for a token that
  can expire or rotate mid-session.
- `fetch` — override the `fetch` implementation (mainly for tests, or a
  runtime without a global one).

### `client.from<T>(table)`

Returns a `TableClient<T>` scoped to `table`. `T` is the row shape — it's
your responsibility to keep it in sync with the resource's `columns()` on
the PHP side; nothing here validates it against the server.

### Reading

```ts
// GET /{table} — list, with the same operators pdo-restify's query string supports
await api.from<Post>('posts')
  .select('id,title,comments(id,body)') // embeds work exactly like the PHP docs describe
  .eq('status', 'published')
  .neq('archived', true)
  .gt('views', 100)
  .gte('views', 100)
  .lt('views', 10000)
  .lte('views', 10000)
  .like('title', '*hello*')   // '*' is the wildcard, not SQL's '%' — see pdo-restify's own docs
  .in('id', [1, 2, 3])
  .order('created_at', 'desc')
  .limit(20)
  .offset(40);

// GET /{table}/{id}
await api.from<Post>('posts').find(1);
await api.from<Post>('posts').find(1, 'id,title,comments(id,body)'); // with select/embeds
```

Calling a filter method (`.eq()`, `.like()`, ...) twice for the same column
keeps only the last one — same as setting the same query-string key twice.

### Writing

```ts
// POST /{table} — single insert
await api.from<Post>('posts').insert({ title: 'Hello', body: '...' });

// POST /{table} — bulk insert (an array, not an object, is what makes it bulk)
await api.from<Post>('posts').insert([{ title: 'A', body: '...' }, { title: 'B', body: '...' }]);

// PATCH /{table}/{id}
await api.from<Post>('posts').update(1, { title: 'Updated' });

// PATCH /{table} — bulk update; each row needs the resource's primary key
await api.from<Post>('posts').updateMany([
  { id: 1, title: 'A' },
  { id: 2, title: 'B' },
]);

// DELETE /{table}/{id}
await api.from('posts').delete(1);

// DELETE /{table} — bulk delete, a list of primary key values
await api.from('posts').deleteMany([1, 2, 3]);
```

Bulk insert/update/delete run in a single transaction on the server — one
bad row fails the whole batch. See the PHP library's
[Bulk operations](../../docs/08-bulk-operations.md) doc.

### Handling results

Every request resolves to:

```ts
type PdoRestifyResult<T> =
  | { data: T; error: null; status: number }
  | { data: null; error: { message: string; status: number }; status: number };
```

This client never throws for an API-level error (a 4xx from pdo-restify) —
check `error` before using `data`. It *can* throw for a genuine network
failure (DNS, connection refused, ...), same as a raw `fetch()` would; wrap
in `try`/`catch` if you need to handle that separately from an API error.

```ts
const { data, error, status } = await api.from('posts').find(999);

if (error) {
  // status is also on the top-level result, so you don't need error.status
  if (status === 404) {
    // not found (or not yours, per policy — pdo-restify doesn't distinguish, on purpose)
  }
}
```

## What this client does *not* do

- **No schema introspection or codegen.** `T` in `.from<T>('posts')` is a
  type assertion, not a validated contract — this client doesn't know your
  server's `Resource` definitions.
- **No caching, retries, or request deduplication.** Every `await` is one
  `fetch` call; build that on top if you need it.
- **No realtime/subscriptions.** pdo-restify itself is a plain request/response
  REST layer — there's nothing to subscribe to.

## Development

```bash
npm install
npm test        # mocked-fetch unit tests + a real end-to-end suite against
                 # an actual `php -S` server running this repo's PHP library
                 # (skipped automatically if `php` isn't on PATH)
npm run typecheck
npm run build    # emits dist/ (ESM + CJS + .d.ts + a plain-<script> global build) via tsup
```

The end-to-end suite (`tests/e2e/`) is the important one: it spawns
`php -S` running `tests/e2e/server.php` — a real `Api` instance from the PHP
package in this repo, backed by a real SQLite file — and drives it with this
client over real HTTP. It's what actually proves this client speaks the wire
protocol the PHP library implements, rather than the protocol the mocked
tests assume it implements.

## License

[LGPL-3.0-or-later](../../LICENSE), same as the PHP library.
