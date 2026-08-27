/**
 * A filter operator understood by pdo-restify's `column=operator.value`
 * query string convention. See the PHP library's docs/04-querying.md.
 */
export type FilterOperator = 'eq' | 'ne' | 'gt' | 'gte' | 'lt' | 'lte' | 'like' | 'in';

/** Sort direction for `.order()`. */
export type OrderDirection = 'asc' | 'desc';

/** The error shape returned by a failed request. */
export interface PdoRestifyError {
  /** The `error` message from the API's JSON body, or the HTTP status text if the body wasn't JSON. */
  message: string;
  /** HTTP status code of the response. */
  status: number;
}

export interface PdoRestifySuccess<T> {
  data: T;
  error: null;
  status: number;
}

export interface PdoRestifyFailure {
  data: null;
  error: PdoRestifyError;
  status: number;
}

/**
 * The result of awaiting any request built by this client — never throws for
 * an API-level error (4xx from pdo-restify); only a network failure or a
 * non-JSON 2xx response throws. Check `error` before using `data`.
 */
export type PdoRestifyResult<T> = PdoRestifySuccess<T> | PdoRestifyFailure;

/**
 * Options accepted by {@link createClient}, applied to every request it builds.
 */
export interface ClientOptions {
  /**
   * Extra headers merged into every request — a plain object, or a function
   * (sync or async) called fresh before each request, for a token that can
   * expire or rotate mid-session.
   */
  headers?: HeadersInit | (() => HeadersInit | Promise<HeadersInit>);
  /**
   * Override the `fetch` implementation used to send requests. Defaults to
   * the global `fetch`. Mainly useful for tests, or a runtime without one
   * globally available.
   */
  fetch?: typeof fetch;
}
