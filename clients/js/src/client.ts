import { TableClient } from './table.js';
import type { ClientOptions } from './types.js';

/**
 * A pdo-restify client bound to one base URL. Build one with
 * {@link createClient} rather than calling this constructor directly.
 */
export class PdoRestifyClient {
  constructor(
    private readonly baseUrl: string,
    private readonly options: ClientOptions = {},
  ) {}

  /** Returns a client scoped to one table/resource. */
  from<T extends Record<string, unknown> = Record<string, unknown>>(table: string): TableClient<T> {
    return new TableClient<T>(this.baseUrl, table, this.options);
  }
}

/**
 * Creates a pdo-restify client.
 *
 * @param baseUrl Where the API is mounted, e.g. `'https://api.example.com/'`
 *                or `'https://api.example.com/v1/'` — include any mount
 *                prefix your server uses. A trailing slash is added if missing.
 * @param options Headers (static or dynamic) and a `fetch` override applied to every request.
 */
export function createClient(baseUrl: string, options?: ClientOptions): PdoRestifyClient {
  return new PdoRestifyClient(baseUrl, options);
}
