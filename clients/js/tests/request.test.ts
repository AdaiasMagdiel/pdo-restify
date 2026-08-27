import { describe, expect, it } from 'vitest';
import { createClient } from '../src/index.js';
import { createFetchMock } from './helpers.js';

describe('query building', () => {
  it('builds filters, select, order and pagination into the query string', async () => {
    const mock = createFetchMock({ status: 200, body: [] });
    const api = createClient('https://api.example.com/', { fetch: mock.fetch });

    await api
      .from('posts')
      .select('id,title')
      .eq('status', 'published')
      .neq('archived', true)
      .gt('views', 10)
      .gte('likes', 5)
      .lt('age_days', 1000)
      .lte('comments', 50)
      .like('title', '*hello*')
      .in('id', [1, 2, 3])
      .order('id', 'desc')
      .limit(20)
      .offset(40);

    const url = new URL(mock.calls[0]!.url);
    expect(url.pathname).toBe('/posts');
    expect(url.searchParams.get('select')).toBe('id,title');
    expect(url.searchParams.get('status')).toBe('eq.published');
    expect(url.searchParams.get('archived')).toBe('ne.true');
    expect(url.searchParams.get('views')).toBe('gt.10');
    expect(url.searchParams.get('likes')).toBe('gte.5');
    expect(url.searchParams.get('age_days')).toBe('lt.1000');
    expect(url.searchParams.get('comments')).toBe('lte.50');
    expect(url.searchParams.get('title')).toBe('like.*hello*');
    expect(url.searchParams.get('id')).toBe('in.1,2,3');
    expect(url.searchParams.get('order')).toBe('id.desc');
    expect(url.searchParams.get('limit')).toBe('20');
    expect(url.searchParams.get('offset')).toBe('40');
  });

  it('lets a later filter on the same column override an earlier one', async () => {
    const mock = createFetchMock({ status: 200, body: [] });
    const api = createClient('https://api.example.com/', { fetch: mock.fetch });

    await api.from('posts').select().eq('status', 'draft').eq('status', 'published');

    const url = new URL(mock.calls[0]!.url);
    expect(url.searchParams.get('status')).toBe('eq.published');
  });

  it('defaults order direction to asc', async () => {
    const mock = createFetchMock({ status: 200, body: [] });
    const api = createClient('https://api.example.com/', { fetch: mock.fetch });

    await api.from('posts').select().order('id');

    const url = new URL(mock.calls[0]!.url);
    expect(url.searchParams.get('order')).toBe('id.asc');
  });

  it('omits select= entirely when no columns are given', async () => {
    const mock = createFetchMock({ status: 200, body: [] });
    const api = createClient('https://api.example.com/', { fetch: mock.fetch });

    await api.from('posts').select();

    const url = new URL(mock.calls[0]!.url);
    expect(url.searchParams.has('select')).toBe(false);
  });

  it('respects a base URL with its own path prefix', async () => {
    const mock = createFetchMock({ status: 200, body: [] });
    const api = createClient('https://api.example.com/v1/', { fetch: mock.fetch });

    await api.from('posts').select();

    const url = new URL(mock.calls[0]!.url);
    expect(url.pathname).toBe('/v1/posts');
  });

  it('adds a trailing slash to the base URL if missing', async () => {
    const mock = createFetchMock({ status: 200, body: [] });
    const api = createClient('https://api.example.com/v1', { fetch: mock.fetch });

    await api.from('posts').select();

    const url = new URL(mock.calls[0]!.url);
    expect(url.pathname).toBe('/v1/posts');
  });
});

describe('headers', () => {
  it('merges static headers into every request', async () => {
    const mock = createFetchMock({ status: 200, body: [] });
    const api = createClient('https://api.example.com/', {
      fetch: mock.fetch,
      headers: { Authorization: 'Bearer token123' },
    });

    await api.from('posts').select();

    expect(mock.calls[0]!.headers.get('Authorization')).toBe('Bearer token123');
    expect(mock.calls[0]!.headers.get('Content-Type')).toBe('application/json');
  });

  it('calls a header function fresh for every request', async () => {
    const mock = createFetchMock({ status: 200, body: [] }, { status: 200, body: [] });
    let token = 'first';
    const api = createClient('https://api.example.com/', {
      fetch: mock.fetch,
      headers: () => ({ Authorization: `Bearer ${token}` }),
    });

    await api.from('posts').select();
    token = 'second';
    await api.from('posts').select();

    expect(mock.calls[0]!.headers.get('Authorization')).toBe('Bearer first');
    expect(mock.calls[1]!.headers.get('Authorization')).toBe('Bearer second');
  });

  it('awaits an async header function', async () => {
    const mock = createFetchMock({ status: 200, body: [] });
    const api = createClient('https://api.example.com/', {
      fetch: mock.fetch,
      headers: async () => {
        await Promise.resolve();

        return { Authorization: 'Bearer async-token' };
      },
    });

    await api.from('posts').select();

    expect(mock.calls[0]!.headers.get('Authorization')).toBe('Bearer async-token');
  });
});

describe('responses', () => {
  it('returns data with a null error on success', async () => {
    const mock = createFetchMock({ status: 200, body: [{ id: 1, title: 'First' }] });
    const api = createClient('https://api.example.com/', { fetch: mock.fetch });

    const result = await api.from('posts').select();

    expect(result.error).toBeNull();
    expect(result.status).toBe(200);
    expect(result.data).toEqual([{ id: 1, title: 'First' }]);
  });

  it('returns null data and an error on a 4xx response', async () => {
    const mock = createFetchMock({ status: 422, body: { error: 'Unknown select column: secret' } });
    const api = createClient('https://api.example.com/', { fetch: mock.fetch });

    const result = await api.from('posts').select();

    expect(result.data).toBeNull();
    expect(result.status).toBe(422);
    expect(result.error).toEqual({ message: 'Unknown select column: secret', status: 422 });
  });

  it('falls back to the HTTP status text when the error body is not the expected shape', async () => {
    const mock = createFetchMock({ status: 500, body: 'not json shaped as {error}' });
    const api = createClient('https://api.example.com/', { fetch: mock.fetch });

    const result = await api.from('posts').select();

    expect(result.data).toBeNull();
    expect(result.error?.status).toBe(500);
    expect(typeof result.error?.message).toBe('string');
  });

  it('treats a 204 as success with null data', async () => {
    const mock = createFetchMock({ status: 204 });
    const api = createClient('https://api.example.com/', { fetch: mock.fetch });

    const result = await api.from('posts').delete(1);

    expect(result.error).toBeNull();
    expect(result.data).toBeNull();
    expect(result.status).toBe(204);
  });

  it('treats an empty (non-204) body as null data on success', async () => {
    const mock = createFetchMock({ status: 200 });
    const api = createClient('https://api.example.com/', { fetch: mock.fetch });

    const result = await api.from('posts').select();

    expect(result.error).toBeNull();
    expect(result.data).toBeNull();
  });
});

describe('laziness and caching', () => {
  it('does not call fetch until awaited', async () => {
    const mock = createFetchMock({ status: 200, body: [] });
    const api = createClient('https://api.example.com/', { fetch: mock.fetch });

    api.from('posts').select().eq('id', 1); // deliberately not awaited

    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(mock.calls).toHaveLength(0);
  });

  it('only calls fetch once even if the request is awaited twice', async () => {
    const mock = createFetchMock({ status: 200, body: [] });
    const api = createClient('https://api.example.com/', { fetch: mock.fetch });

    const request = api.from('posts').select();

    await request;
    await request;

    expect(mock.calls).toHaveLength(1);
  });
});
