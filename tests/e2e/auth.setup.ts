import { expect, test as setup } from '@playwright/test';
import { generate } from 'otplib';
import { AUTH_STATE } from '../../playwright.config';
import { E2E_EMAIL, E2E_PASSWORD, E2E_TOTP_SECRET } from './support/credentials';

/**
 * Signs in once as the e2e administrator (password, then a TOTP code from the fixed secret) and
 * saves the session for every other test. Fortify refuses a code used twice, so if the previous
 * run used this window's code the challenge shows an error and the next code is tried.
 */
setup('sign in as the e2e administrator', async ({ page }) => {
    // Once per run, on a possibly cold dev server: allowed more than an ordinary test.
    setup.setTimeout(300_000);
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Email address').fill(E2E_EMAIL);
    await page.getByLabel('Password', { exact: true }).fill(E2E_PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL(/two-factor-challenge|\/dashboard\//, { waitUntil: 'commit' });

    for (let attempt = 0; attempt < 2 && page.url().includes('two-factor-challenge'); attempt++) {
        if (attempt > 0) {
            await page.waitForTimeout(30_000 - (Date.now() % 30_000) + 1_000);
        }
        await page.getByLabel('Authentication code').fill(await generate({ secret: E2E_TOTP_SECRET }));
        await page.getByRole('button', { name: 'Continue' }).click();
        // Either the dashboard, or the error summary for a refused code: never a guess at idleness.
        await Promise.race([page.waitForURL(/\/dashboard\//, { waitUntil: 'commit' }), page.getByRole('alert').waitFor()]);
    }

    await expect(page).toHaveURL(/\/dashboard\/home/);
    await page.context().storageState({ path: AUTH_STATE });
});
