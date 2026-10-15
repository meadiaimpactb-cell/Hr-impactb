import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

// Each build gets an id; it is baked into the app and written to /static/version.json so open tabs can tell a new release is live.
const BUILD_ID = String(Date.now())
const versionFile = { name: 'hc-version', generateBundle() { this.emitFile({ type: 'asset', fileName: 'version.json', source: JSON.stringify({ build: BUILD_ID }) }) } }

export default defineConfig({
  base: '/static/', // assets folder; routes are /app/* (portal) and / (landing) — kept distinct from any real directory
  plugins: [vue(), versionFile],
  define: { __BUILD_ID__: JSON.stringify(BUILD_ID) },
  resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
  server: {
    port: 5173,
    proxy: { '/api': { target: 'http://127.0.0.1:8000', changeOrigin: true } },
  },
  build: {
    outDir: '../backend/public/static',
    emptyOutDir: true,
    chunkSizeWarningLimit: 800,
    rollupOptions: {
      input: { index: 'index.html', landing: 'landing.html' }, // portal + public landing page
      output: { manualChunks: (id) => (id.includes('node_modules') ? 'vendor' : undefined) },
    },
  },
})
