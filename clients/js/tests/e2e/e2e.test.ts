import { type ChildProcess, execFileSync, spawn } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { createClient } from '../../src/index.js';

const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * End-to-end tests: a real `php -S` server running the actual PHP
 * pdo-restify library from this repo (tests/e2e/server.php), hit with real
 * `fetch()` calls from a real client — no mocked network layer anywhere in
 * this file. This is what actually proves the JS client speaks the wire
 * protocol the PHP library implements, rather than the protocol I assumed
 * it implements while writing request.test.ts's mocks.
 *
 * Skips itself if `php` isn't on PATH, so `npm test` still works in an
 * environment without PHP — mirrors how the PHP side's own MySQL/MariaDB
 * integration tests skip themselves without a configured driver.
 */
const hasPhp = (() => {
  try {
    execFileSync('php', ['-v'], { stdio: 'ignore' });

    return true;
  } catch {
    return false;
  }
})();

describe.skipIf(!hasPhp)('end-to-end against a real PHP server', () => {
  const port = 8791;
  const baseUrl = `http://127.0.0.1:${port}/`;

  let server: ChildProcess;
  let dbDir: string;

  beforeAll(async () => {
    dbDir = mkdtempSync(join(tmpdir(), 'pdo-restify-e2e-'));
    const dbFile = join(dbDir, 'e2e.sqlite');

    server = spawn('php', ['-S', `127.0.0.1:${port}`, 'server.php'], {
      cwd: __dirname,
      env: { ...process.env, PDO_RESTIFY_E2E_DB: dbFile },
      stdio: 'ignore',
    });

    await waitForServer(baseUrl);
  });

  afterAll(() => {
    server.kill();

    if (existsSync(dbDir)) {
      rmSync(dbDir, { recursive: true, force: true });
    }
  });

  it('rejects an unauthenticated request', async () => {
    const api = createClient(baseUrl);

    const result = await api.from('posts').select();

    expect(result.status).toBe(403);
    expect(result.error?.message).toContain('Authentication required');
    expect(result.data).toBeNull();
  });

  it('runs a full CRUD lifecycle against the real server', async () => {
    const api = createClient(baseUrl, { headers: { 'X-User-Id': '1' } });
    const posts = api.from<{ id: number; title: string; body: string; user_id: number }>('posts');

    const created = await posts.insert({ title: 'First post', body: 'Hello, world.' });
    expect(created.error).toBeNull();
    expect(created.data).toMatchObject({ title: 'First post', user_id: 1 });
    const id = (created.data as { id: number }).id;

    const found = await posts.find(id);
    expect(found.data?.title).toBe('First post');

    const updated = await posts.update(id, { title: 'Updated title' });
    expect(updated.error).toBeNull();
    expect(updated.data?.title).toBe('Updated title');

    const listed = await posts.select().eq('id', id);
    expect(listed.data).toHaveLength(1);
    expect(listed.data?.[0]?.title).toBe('Updated title');

    const deleted = await posts.delete(id);
    expect(deleted.error).toBeNull();
    expect(deleted.status).toBe(204);

    const afterDelete = await posts.find(id);
    expect(afterDelete.status).toBe(404);
  });

  it('scopes rows per user, exactly like a direct policy check would', async () => {
    const alice = createClient(baseUrl, { headers: { 'X-User-Id': '10' } });
    const bob = createClient(baseUrl, { headers: { 'X-User-Id': '11' } });

    const aliceCreated = await alice.from('posts').insert({ title: "Alice's post", body: '...' });
    const alicePostId = (aliceCreated.data as { id: number }).id;

    const bobsView = await bob.from('posts').select().eq('id', alicePostId);
    expect(bobsView.data).toEqual([]);

    const bobsDirectFetch = await bob.from('posts').find(alicePostId);
    expect(bobsDirectFetch.status).toBe(404);

    const alicesView = await alice.from('posts').find(alicePostId);
    expect(alicesView.data?.title).toBe("Alice's post");
  });

  it('cannot reassign a policy-scoped column via update, even over real HTTP', async () => {
    // The same vulnerability class closed in Api::update() itself
    // (tests/Feature/SecurityTest.php on the PHP side) — re-proven here
    // over the actual network path a JS consumer would use.
    const api = createClient(baseUrl, { headers: { 'X-User-Id': '20' } });
    const posts = api.from<{ id: number; title: string; body: string; user_id: number }>('posts');

    const created = await posts.insert({ title: 'Mine', body: '...' });
    const id = (created.data as { id: number }).id;

    const result = await posts.update(id, { title: 'Still mine?', user_id: 999 });

    expect(result.data?.user_id).toBe(20);
  });

  it('bulk inserts and bulk deletes in one request each', async () => {
    const api = createClient(baseUrl, { headers: { 'X-User-Id': '30' } });
    const posts = api.from<{ id: number; title: string; body: string }>('posts');

    const created = await posts.insert([
      { title: 'Bulk A', body: '...' },
      { title: 'Bulk B', body: '...' },
    ]);

    expect(created.error).toBeNull();
    const rows = created.data as Array<{ id: number; title: string }>;
    expect(rows).toHaveLength(2);
    expect(rows.map((row) => row.title)).toEqual(['Bulk A', 'Bulk B']);

    const ids = rows.map((row) => row.id);
    const deleted = await posts.deleteMany(ids);
    expect(deleted.status).toBe(204);

    const remaining = await posts.select().in('id', ids);
    expect(remaining.data).toEqual([]);
  });

  it('embeds a hasMany relation end to end', async () => {
    const api = createClient(baseUrl, { headers: { 'X-User-Id': '40' } });
    const posts = api.from<{
      id: number;
      title: string;
      body: string;
      comments: Array<{ id: number; body: string }>;
    }>('posts');
    const comments = createClient(baseUrl).from('comments');

    const post = await posts.insert({ title: 'Post with comments', body: '...' });
    const postId = (post.data as { id: number }).id;

    await comments.insert({ post_id: postId, body: 'First comment' });
    await comments.insert({ post_id: postId, body: 'Second comment' });

    const result = await posts.find(postId, 'id,title,comments(id,body)');

    expect(result.error).toBeNull();
    expect(result.data?.comments).toHaveLength(2);
    expect(result.data?.comments.map((c) => c.body)).toEqual(['First comment', 'Second comment']);
  });

  it('surfaces a validation error from the real server', async () => {
    const api = createClient(baseUrl, { headers: { 'X-User-Id': '1' } });

    const result = await api.from('posts').select('this_column_does_not_exist');

    expect(result.status).toBe(422);
    expect(result.data).toBeNull();
    expect(result.error?.message).toContain('this_column_does_not_exist');
  });
});

async function waitForServer(baseUrl: string, timeoutMs = 5000): Promise<void> {
  const start = Date.now();

  while (Date.now() - start < timeoutMs) {
    try {
      await fetch(baseUrl);

      return;
    } catch {
      await new Promise((resolve) => setTimeout(resolve, 50));
    }
  }

  throw new Error(`php -S did not become ready within ${timeoutMs}ms`);
}
