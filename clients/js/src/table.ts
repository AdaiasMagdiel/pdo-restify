import { PdoRestifyRequest } from './request.js';
import type { ClientOptions } from './types.js';

/**
 * Entry point for one table, returned by {@link PdoRestifyClient.from}. Each
 * method builds a {@link PdoRestifyRequest} — nothing is sent until it's
 * `await`ed.
 */
export class TableClient<T extends Record<string, unknown> = Record<string, unknown>> {
  constructor(
    private readonly baseUrl: string,
    private readonly table: string,
    private readonly options: ClientOptions,
  ) {}

  /**
   * `GET /{table}` — list rows. Chain `.eq()`, `.order()`, `.limit()`, etc.
   * before awaiting.
   */
  select(columns?: string): PdoRestifyRequest<T[]> {
    return new PdoRestifyRequest<T[]>(this.baseUrl, 'GET', this.table, this.options).select(columns);
  }

  /** `GET /{table}/{id}` — fetch a single row. */
  find(id: string | number, columns?: string): PdoRestifyRequest<T> {
    return new PdoRestifyRequest<T>(this.baseUrl, 'GET', `${this.table}/${encodeURIComponent(id)}`, this.options).select(
      columns,
    );
  }

  /**
   * `POST /{table}` — insert a row, or bulk-insert if given an array. Policy
   * conditions on the server always win over whatever's in `rows`, for any
   * column they scope — see the PHP library's security model docs.
   */
  insert(rows: Partial<T> | Array<Partial<T>>): PdoRestifyRequest<T | T[]> {
    return new PdoRestifyRequest<T | T[]>(this.baseUrl, 'POST', this.table, this.options, rows);
  }

  /** `PATCH /{table}/{id}` — update a single row. */
  update(id: string | number, data: Partial<T>): PdoRestifyRequest<T> {
    return new PdoRestifyRequest<T>(this.baseUrl, 'PATCH', `${this.table}/${encodeURIComponent(id)}`, this.options, data);
  }

  /**
   * `PATCH /{table}` — bulk update. Each row must include the resource's
   * primary key (`id` by default), identifying which row it updates.
   */
  updateMany(rows: Array<Partial<T> & Record<string, unknown>>): PdoRestifyRequest<T[]> {
    return new PdoRestifyRequest<T[]>(this.baseUrl, 'PATCH', this.table, this.options, rows);
  }

  /** `DELETE /{table}/{id}` — delete a single row. */
  delete(id: string | number): PdoRestifyRequest<null> {
    return new PdoRestifyRequest<null>(this.baseUrl, 'DELETE', `${this.table}/${encodeURIComponent(id)}`, this.options);
  }

  /** `DELETE /{table}` — bulk delete, given a list of primary key values. */
  deleteMany(ids: Array<string | number>): PdoRestifyRequest<null> {
    return new PdoRestifyRequest<null>(this.baseUrl, 'DELETE', this.table, this.options, ids);
  }
}
