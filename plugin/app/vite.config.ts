import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'path'

export default defineConfig({
  plugins: [react()],
  base: './',
  resolve: {
    alias: { '@': path.resolve(__dirname, './src') },
  },
  build: {
    outDir: '../assets/dist',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: '/src/main.tsx',
      output: {
        entryFileNames: 'assets/index.js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name][extname]',
      },
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    allowedHosts: 'all',
  },
})
