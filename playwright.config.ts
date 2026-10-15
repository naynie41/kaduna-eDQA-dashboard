import { defineConfig, devices } from '@playwright/test';

// Playwright (ARCHITECTURE.md §10): `make e2e` runs it in the `playwright` service
// (compose.dev.yml, profile e2e) against the running dev stack. Inside that container the global
// setup forwards localhost:8080 to the web service and localhost:5173 to Vite, so the browser sees
// the same origins as a developer's (APP_URL, the CSP, the Vite dev server).

export const AUTH_STATE = 'storage/e2e/admin.json';

const desktop = { width: 1280, height: 800 };
const phone = { width: 360, height: 740 };

export default defineConfig({
    testDir: 'tests/e2e',
    outputDir: 'storage/e2e/results',
    globalSetup: './tests/e2e/global-setup.ts',
    reporter: [['list']],
    workers: 2,
    // The dev stack is slow on a cold start (PHP on a Windows bind mount, Vite transforming each
    // module on first request), so the limits are generous; a passing run is much faster.
    timeout: 120_000,
    expect: { timeout: 20_000 },
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8080',
        navigationTimeout: 90_000,
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: desktop, storageState: AUTH_STATE }, dependencies: ['setup'] },
        { name: 'phone', use: { ...devices['Desktop Chrome'], viewport: phone, storageState: AUTH_STATE }, dependencies: ['setup'] },
    ],
});
