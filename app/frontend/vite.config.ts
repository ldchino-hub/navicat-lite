import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { readFileSync } from 'node:fs';
import { resolve } from 'path';

const uiRoot = resolve(__dirname, '../../navicat-ui/src');
const frontendRoot = __dirname;

function readAppVersion(): string {
  try {
    return readFileSync(resolve(__dirname, '../VERSION'), 'utf8').trim() || '1.0.0';
  } catch {
    return '1.0.0';
  }
}

const appVersion = readAppVersion();

/** Force a single copy of shared libs when bundling aliased navicat-ui source. */
const singleton = (pkg: string) => resolve(frontendRoot, 'node_modules', pkg);

export default defineConfig(({ command, mode }) => ({
  base: './',
  plugins: [react({ jsxRuntime: 'automatic' })],
  define: {
    'process.env.NODE_ENV': JSON.stringify(mode === 'production' ? 'production' : 'development'),
    'import.meta.env.VITE_APP_VERSION': JSON.stringify(appVersion),
  },
  resolve: {
    dedupe: ['react', 'react-dom', '@tanstack/react-query', '@tanstack/query-core', 'zustand'],
    alias: {
      '@navicat-ui/styles': resolve(__dirname, '../../navicat-ui/src/index.css'),
      '@': uiRoot,
      '@navicat/ui': resolve(__dirname, '../../navicat-ui/src/index.ts'),
      ...(command === 'build'
        ? {
            '@tanstack/react-query': singleton('@tanstack/react-query'),
            '@tanstack/query-core': singleton('@tanstack/query-core'),
            zustand: singleton('zustand'),
          }
        : {}),
    },
  },
  optimizeDeps: {
    include: [
      'react', 'react-dom', '@tanstack/react-query', 'zustand',
      'reactflow', 'ag-grid-react', 'lucide-react',
    ],
  },
  server: {
    host: '127.0.0.1',
    port: 5184,
    strictPort: true,
    fs: { allow: [resolve(__dirname, '../..')] },
    proxy: {
      '/api': { target: 'http://127.0.0.1:8081', changeOrigin: true },
    },
  },
  esbuild: {
    jsx: 'automatic',
    jsxDev: false,
    minifyIdentifiers: false,
  },
  build: {
    outDir: resolve(__dirname, '../public'),
    emptyOutDir: false,
    sourcemap: true,
    target: 'es2020',
    modulePreload: { polyfill: false },
    rollupOptions: {
      output: {
        minifyInternalExports: false,
      },
    },
  },
}));
