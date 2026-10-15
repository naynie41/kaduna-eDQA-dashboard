/**
 * Warms the stack before the tests, so none pays for a cold start: PHP (the first request on a
 * Windows bind mount takes seconds) and the Vite dev server (after a production build its first
 * module can take most of a minute). In the e2e container both are already forwarded
 * (tests/e2e/run.sh). Without the dev server running (no public/hot) the Vite requests just fail.
 */
export default async function globalSetup(): Promise<void> {
    const base = process.env.E2E_BASE_URL ?? 'http://localhost:8080';
    const vite = process.env.E2E_VITE_URL ?? 'http://localhost:5173';

    for (const url of [`${base}/login`, `${vite}/@vite/client`, `${vite}/resources/js/app.tsx`, `${vite}/resources/js/pages/auth/login.tsx`, `${vite}/resources/js/pages/dashboard/home.tsx`, `${vite}/resources/js/components/TrendChart.tsx`]) {
        await fetch(url).catch(() => undefined);
    }
}
