import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      // Proxy API calls during dev so hitting http://localhost:5173/... reaches backend
      '/auth': { target: 'http://127.0.0.1:8080', changeOrigin: true },
      '/stats': { target: 'http://127.0.0.1:8080', changeOrigin: true },
      '/system': { target: 'http://127.0.0.1:8080', changeOrigin: true },
      '/put': { target: 'http://127.0.0.1:8080', changeOrigin: true },
      '/get': { target: 'http://127.0.0.1:8080', changeOrigin: true },
      '/keys': { target: 'http://127.0.0.1:8080', changeOrigin: true },
      '/search': { target: 'http://127.0.0.1:8080', changeOrigin: true },
      '/invalidate': { target: 'http://127.0.0.1:8080', changeOrigin: true },
      '/flush': { target: 'http://127.0.0.1:8080', changeOrigin: true },
      '/health': { target: 'http://127.0.0.1:8080', changeOrigin: true },
    }
  },
});
