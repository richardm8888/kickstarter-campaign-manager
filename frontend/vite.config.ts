import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'node:path'

const API_PROXY = {
  '/api': {
    target: process.env.VITE_API_URL ?? 'http://localhost:8000',
    changeOrigin: true,
  },
}

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    proxy: API_PROXY,
  },
  // `vite preview` does not inherit the dev server's proxy, so without
  // this the only way to exercise a production build is to deploy it.
  preview: {
    proxy: API_PROXY,
  },
})
