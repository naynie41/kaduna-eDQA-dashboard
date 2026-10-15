import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';
import { compression } from 'vite-plugin-compression2';

// Wayfinder's plugin runs `php artisan wayfinder:generate`. Where there is no PHP (the Docker
// assets stage, the dev `vite` container) the files are generated elsewhere and this is set
// (ARCHITECTURE.md D-19).
const skipWayfinder = process.env.WAYFINDER_SKIP === '1';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        inertia(),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        ...(skipWayfinder ? [] : [wayfinder({ formVariants: true })]),
        // Precompressed .br/.gz next to each asset; Caddy serves them (`precompressed br gzip`).
        compression({ algorithms: ['brotliCompress', 'gzip'] }),
    ],
    // Pre-bundle the lazily imported chart library at startup: discovered at runtime, it makes
    // the dev server re-optimise and reload the page mid-visit.
    optimizeDeps: {
        include: ['recharts'],
    },
    server: {
        // Reachable from the host when running in the dev container; HMR back to localhost.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
        },
        watch: {
            usePolling: process.env.VITE_USE_POLLING === '1',
            // Chokidar's default 100 ms poll keeps a bind-mounted repo busy; 1 s is plenty for HMR.
            interval: 1000,
            binaryInterval: 3000,
            // Polling cost grows with every file watched: leave out everything the frontend never
            // imports, notably public/build (a production build leaves ~150 files there).
            ignored: [
                '**/vendor/**',
                '**/discovery/**',
                '**/storage/**',
                '**/.claude/**',
                '**/public/build/**',
                '**/tests/**',
                '**/database/**',
                '**/docs/**',
                '**/bootstrap/cache/**',
            ],
        },
    },
});
