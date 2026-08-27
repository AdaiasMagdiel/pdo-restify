import { describe, expect, it } from 'vitest';
import { createClient } from '../src/index.js';
import { createFetchMock } from './helpers.js';

describe('TableClient wire format', () => {
  it('find() issues a GET to /{table}/{id}, url-encoding the id', async () => {
    const mock = createFetchMock({ status: 200, body: { id: 1 } });
    const api = createClient('https://api.example.com/', { fetch: mock.fetch });

    await api.from('posts').find('a b');

    expect(mock.calls[0]!.method).toBe('GET');
    const url = new URL(mock.calls[0]!.url);
    expect(url.pathname).toBe('/posts/a%20b');
  });

  it('find() forwards a select= for column restriction/embeds', async () => {
    const mock = createFetchMock({ status: 200, body: { id: 1 } });
    const api = createClient('https://api.example.com/', { fetch: mock.fetch });

    await api.from('posts').find(1, 'id,comments(id,body)');

    const url = new URL(mock.calls[0]!.url);
    expect(url.searchParams.get('select')).toBe('id,comments(id,body)');
  });

  it('insert() sends a single object as a POST body', async () => {
    const mock = createFetchMock({ status: 200, body: { id: 1, title: 'A' } });
    const api = createClient('https://api.example.com/', { fetch: mock.fetch });

    await api.from('posts').insert({ title: 'A' });

    expect(mock.calls[0]!.method).toBe('POST');
    expect(new URL(mock.calls[0]!.url).pathname).toBe('/posts');
    expect(mock.calls[0]!.body).toEqual({ title: 'A' });
  });

  it('insert() sends an array as-is for a bulk request', async () => {
    const mock = createFetchMock({ status: 200, body: [] });
    const api = createClient('https://api.example.com/', { fetch: mock.fetch });

    await api.from('posts').insert([{ title: 'A' }, { title: 'B' }]);

    expect(mock.calls[0]!.body).toEqual([{ title: 'A' }, { title: 'B' }]);
  });

  it('update() sends a PATCH to /{table}/{id}', async () => {
    const mock = createFetchMock({ status: 200, body: { id: 1, title: 'A' } });
    const api = createClient('https://api.example.com/', { fetch: mock.fetch });

    await api.from('posts').update(1, { title: 'A' });

    expect(mock.calls[0]!.method).toBe('PATCH');
    expect(new URL(mock.calls[0]!.url).pathname).toBe('/posts/1');
    expect(mock.calls[0]!.body).toEqual({ title: 'A' });
  });

  it('updateMany() sends a PATCH to /{table} with a row array', async () => {
    const mock = createFetchMock({ status: 200, body: [] });
    const api = createClient('https://api.example.com/', { fetch: mock.fetch });

    await api.from('posts').updateMany([
      { id: 1, title: 'A' },
      { id: 2, title: 'B' },
    ]);

    expect(mock.calls[0]!.method).toBe('PATCH');
    expect(new URL(mock.calls[0]!.url).pathname).toBe('/posts');
    expect(mock.calls[0]!.body).toEqual([
      { id: 1, title: 'A' },
      { id: 2, title: 'B' },
    ]);
  });

  it('delete() sends a DELETE to /{table}/{id} with no body', async () => {
    const mock = createFetchMock({ status: 204 });
    const api = createClient('https://api.example.com/', { fetch: mock.fetch });

    await api.from('posts').delete(1);

    expect(mock.calls[0]!.method).toBe('DELETE');
    expect(new URL(mock.calls[0]!.url).pathname).toBe('/posts/1');
    expect(mock.calls[0]!.body).toBeNull();
  });

  it('deleteMany() sends a DELETE to /{table} with an id array body', async () => {
    const mock = createFetchMock({ status: 204 });
    const api = createClient('https://api.example.com/', { fetch: mock.fetch });

    await api.from('posts').deleteMany([1, 2, 3]);

    expect(mock.calls[0]!.method).toBe('DELETE');
    expect(new URL(mock.calls[0]!.url).pathname).toBe('/posts');
    expect(mock.calls[0]!.body).toEqual([1, 2, 3]);
  });
});
