import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { viteSingleFile } from 'vite-plugin-singlefile'

// F1 frontend foundation configuration.
// Vite is development/build tooling only — the SPMS backend remains PHP.
// ONE application: npm run dev → port 5173 → proxy /api/* to PHP on 8080.
// Environment selection (real/demo/test) is purely server-side via .env or
// process env SPMS_DATA_ENVIRONMENT — the browser never selects a database.
export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '')

    // Local development proxy: /api/* is forwarded to the PHP backend.
    // The PHP process determines which database is active via SPMS_DATA_ENVIRONMENT.
    const proxyTarget = env.VITE_DEV_PROXY_TARGET || 'http://127.0.0.1:8080'
    const port = Number(env.PORT) || 5173

    return {
        base: './',
        plugins: [react(), tailwindcss(), viteSingleFile()],
        server: {
            port,
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
