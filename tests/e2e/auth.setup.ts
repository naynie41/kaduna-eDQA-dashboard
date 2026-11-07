import { expect, test as setup } from '@playwright/test';
import { AUTH_STATE } from '../../playwright.config';
import { E2E_EMAIL, E2E_PASSWORD } from './support/credentials';

/**
 * Signs in once as the e2e administrator (email and password; no MFA, D-35) and saves the session
 * for every other test.
 */
setup('sign in as the e2e administrator', async ({ page }) => {
    // Once per run, on a possibly cold dev server: allowed more than an ordinary test.
    setup.setTimeout(300_000);
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Email address').fill(E2E_EMAIL);
    await page.getByLabel('Password', { exact: true }).fill(E2E_PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();

    // After sign-in, the admin area (D-78): /admin opens Data sources.
    // The first load of the admin area on a cold dev server can take a while.
    await expect(page).toHaveURL(/\/admin\/sources/, { timeout: 180_000 });
    await page.context().storageState({ path: AUTH_STATE });
});
