import { defineConfig } from 'tsup';

export default defineConfig([
  {
    entry: ['src/index.ts'],
    format: ['esm', 'cjs'],
    dts: true,
    sourcemap: true,
    clean: true,
    target: 'es2020',
  },
  {
    // A plain <script> build with zero build step required on the
    // consumer's end — for anyone who doesn't want npm/TypeScript/a
    // bundler at all. Exposes `window.PdoRestify`. Not listed in
    // package.json's "exports" (npm/bundler users get the ESM/CJS build
    // above); this is meant to be pulled straight from a CDN, e.g.
    // https://cdn.jsdelivr.net/npm/@adaiasmagdiel/pdo-restify/dist/index.global.js
    entry: { index: 'src/index.ts' },
    format: ['iife'],
    globalName: 'PdoRestify',
    outExtension: () => ({ js: '.global.js' }),
    minify: true,
    sourcemap: true,
    target: 'es2020',
  },
]);
