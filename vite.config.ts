import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

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
        // Runs `php artisan wayfinder:generate` on every build; see ARCHITECTURE.md D-19
        // for the Docker assets stage, which has no PHP.
        wayfinder({
            formVariants: true,
        }),
    ],
    server: {
        watch: {
            ignored: ['**/vendor/**', '**/discovery/**', '**/.claude/**'],
        },
    },
});
