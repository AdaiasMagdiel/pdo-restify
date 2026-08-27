import { vi } from 'vitest';

export interface RecordedRequest {
  url: string;
  method: string;
  headers: Headers;
  body: unknown;
}

/**
 * A fake `fetch` that records every call and replays canned responses in
 * order. Pass it via `{ fetch: mock.fetch }` to `createClient()`.
 */
export function createFetchMock(...responses: Array<{ status: number; body?: unknown }>) {
  const calls: RecordedRequest[] = [];
  let index = 0;

  const fetchMock = vi.fn(async (input: string | URL, init?: RequestInit) => {
    const response = responses[index] ?? { status: 200, body: null };
    index++;

    calls.push({
      url: input.toString(),
      method: init?.method ?? 'GET',
      headers: new Headers(init?.headers),
      body: typeof init?.body === 'string' ? JSON.parse(init.body) : init?.body,
    });

    // Null-body statuses (204/205/304) reject even an empty string as a body.
    const isNullBodyStatus = response.status === 204 || response.status === 205 || response.status === 304;
    const body = isNullBodyStatus ? null : response.body === undefined ? '' : JSON.stringify(response.body);

    return new Response(body, {
      status: response.status,
      headers: { 'Content-Type': 'application/json' },
    });
  });

  return { fetch: fetchMock as unknown as typeof fetch, calls };
}
