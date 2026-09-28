import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
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
            // Downloaded at build time and served from public/build (self-hosted, D-09).
            // Replaced by the design system's fonts when the UI is restyled.
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
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
            ignored: ['**/vendor/**', '**/discovery/**', '**/storage/**', '**/.claude/**'],
        },
    },
});
