import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

// Separate from vite.config.ts so unit tests never run the Laravel or Wayfinder plugins
// (and need no PHP). Scope: the pure helpers in resources/js/lib and the components, rendered
// into jsdom with Testing Library and checked with axe (CONVENTION.md §8, ACCESSIBILITY.md).
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['resources/js/lib/**/*.test.{ts,tsx}', 'resources/js/components/**/*.test.tsx'],
        environment: 'jsdom',
        setupFiles: ['resources/js/test/setup.ts'],
    },
});
