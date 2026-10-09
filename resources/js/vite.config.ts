import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { fileURLToPath } from 'node:url'

/**
 * Собираем интерфейс в ../../dist с постоянными именами файлов: Laravel
 * отдаёт их сам (маршрут assets пакета), так что ставящим пакет Node не нужен.
 */
export default defineConfig({
  plugins: [react(), tailwindcss()],
  build: {
    outDir: fileURLToPath(new URL('../../dist', import.meta.url)),
    emptyOutDir: true,
    cssCodeSplit: false,
    // Почти весь вес — иконки Phosphor; один файл проще отдавать из пакета.
    chunkSizeWarningLimit: 900,
    rollupOptions: {
      input: fileURLToPath(new URL('./src/main.tsx', import.meta.url)),
      output: {
        entryFileNames: 'app.js',
        chunkFileNames: 'app-[name].js',
        assetFileNames: (info) => (info.names?.[0]?.endsWith('.css') ? 'app.css' : '[name][extname]'),
      },
    },
  },
})
