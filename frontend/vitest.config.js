import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'

// Frontend verification tests (B4 §30). Vitest is the Vite-native runner;
// this config exists for tests only and never affects the production
// build (vite.config.js stays the single build authority).
export default defineConfig({
    plugins: [react()],
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./src/test/setup.js'],
        include: ['src/**/*.test.{js,jsx}'],
        fileParallelism: false,
    },
})
