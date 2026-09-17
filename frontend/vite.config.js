import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { viteSingleFile } from 'vite-plugin-singlefile'

// F1 frontend foundation configuration.
// Vite is development/build tooling only — the SPMS backend remains PHP.
export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '')

    // Local development proxy: /api/* is forwarded to the PHP backend so the
    // React app and the API share one origin during development.
    const proxyTarget = env.VITE_DEV_PROXY_TARGET || 'http://127.0.0.1:8080'

    return {
        base: './',
        plugins: [react(), tailwindcss(), viteSingleFile()],
        server: {
            port: 5173,
            proxy: {
                '/api': {
                    target: proxyTarget,
                    changeOrigin: true,
                },
            },
        },
        build: {
            outDir: 'dist',
            sourcemap: false,
        },
    }
})
