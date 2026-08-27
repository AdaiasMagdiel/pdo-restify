import type { ClientOptions, FilterOperator, OrderDirection, PdoRestifyResult } from './types.js';

/**
 * A single, lazily-executed request against one pdo-restify endpoint.
 *
 * Nothing is sent over the network until this is `await`ed (or `.then()` is
 * called explicitly) — building one via `.select()`/`.eq()`/... just
 * accumulates query params. It's safe to `await` more than once; the
 * underlying fetch only runs once, and the same result is reused.
 *
 * The filter/order/pagination methods (`.eq()`, `.order()`, `.limit()`, ...)
 * only make sense on a `GET` list request; calling them on an insert/update/
 * delete request is harmless (they just add unused query params) but not
 * meaningful — {@link TableClient} only exposes them where they apply.
 */
export class PdoRestifyRequest<T> implements PromiseLike<PdoRestifyResult<T>> {
  private readonly params = new URLSearchParams();
  private promise: Promise<PdoRestifyResult<T>> | null = null;

  constructor(
    private readonly baseUrl: string,
    private readonly method: string,
    private readonly path: string,
    private readonly options: ClientOptions,
    private readonly body?: unknown,
  ) {}

  /** Restrict returned columns, or embed a relation — e.g. `'id,title,comments(id,body)'`. */
  select(columns?: string): this {
    if (columns) {
      this.params.set('select', columns);
    }

    return this;
  }

  eq(column: string, value: string | number | boolean): this {
    return this.filter(column, 'eq', value);
  }

  neq(column: string, value: string | number | boolean): this {
    return this.filter(column, 'ne', value);
  }

  gt(column: string, value: string | number): this {
    return this.filter(column, 'gt', value);
  }

  gte(column: string, value: string | number): this {
    return this.filter(column, 'gte', value);
  }

  lt(column: string, value: string | number): this {
    return this.filter(column, 'lt', value);
  }

  lte(column: string, value: string | number): this {
    return this.filter(column, 'lte', value);
  }

  /** `pattern` uses `*` as the wildcard, matching pdo-restify's own convention (not SQL's `%`). */
  like(column: string, pattern: string): this {
    return this.filter(column, 'like', pattern);
  }

  in(column: string, values: Array<string | number>): this {
    return this.filter(column, 'in', values.join(','));
  }

  order(column: string, direction: OrderDirection = 'asc'): this {
    this.params.set('order', `${column}.${direction}`);

    return this;
  }

  limit(count: number): this {
    this.params.set('limit', String(count));

    return this;
  }

  offset(count: number): this {
    this.params.set('offset', String(count));

    return this;
  }

  then<TResult1 = PdoRestifyResult<T>, TResult2 = never>(
    onfulfilled?: ((value: PdoRestifyResult<T>) => TResult1 | PromiseLike<TResult1>) | null,
    onrejected?: ((reason: unknown) => TResult2 | PromiseLike<TResult2>) | null,
  ): PromiseLike<TResult1 | TResult2> {
    return this.execute().then(onfulfilled, onrejected);
  }

  private filter(column: string, operator: FilterOperator, value: string | number | boolean): this {
    this.params.set(column, `${operator}.${value}`);

    return this;
  }

  private execute(): Promise<PdoRestifyResult<T>> {
    this.promise ??= this.run();

    return this.promise;
  }

  private async run(): Promise<PdoRestifyResult<T>> {
    const base = this.baseUrl.endsWith('/') ? this.baseUrl : `${this.baseUrl}/`;
    const url = new URL(this.path.replace(/^\/+/, ''), base);

    const query = this.params.toString();
    if (query) {
      url.search = query;
    }

    const headers = new Headers({ 'Content-Type': 'application/json', Accept: 'application/json' });
    const extra = typeof this.options.headers === 'function' ? await this.options.headers() : this.options.headers;
    if (extra) {
      new Headers(extra).forEach((value, key) => headers.set(key, value));
    }

    const fetchImpl = this.options.fetch ?? fetch;

    const response = await fetchImpl(url, {
      method: this.method,
      headers,
      body: this.body === undefined ? null : JSON.stringify(this.body),
    });

    return this.toResult(response);
  }

  private async toResult(response: Response): Promise<PdoRestifyResult<T>> {
    const status = response.status;

    if (status === 204) {
      return { data: null as T, error: null, status };
    }

    const text = await response.text();
    const json: unknown = text === '' ? null : JSON.parse(text);

    if (!response.ok) {
      const message = isErrorBody(json) ? json.error : response.statusText;

      return { data: null, error: { message, status }, status };
    }

    return { data: json as T, error: null, status };
  }
}

function isErrorBody(value: unknown): value is { error: string } {
  return typeof value === 'object' && value !== null && 'error' in value && typeof (value as { error: unknown }).error === 'string';
}
