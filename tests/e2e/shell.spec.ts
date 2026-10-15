import { expect, test, type Locator, type Page } from '@playwright/test';
import { checkA11y } from './support/a11y';

/** Press Tab until the target has focus, as a keyboard user would; fails if it never does. */
async function tabTo(page: Page, target: Locator, limit = 80): Promise<void> {
    for (let i = 0; i < limit; i++) {
        await page.keyboard.press('Tab');
        if (await target.evaluate((el) => el === document.activeElement)) {
            return;
        }
    }
    throw new Error('Never reached the element by pressing Tab.');
}

test.describe('the app shell', () => {
    test('the skip link is the first stop and lands on main', async ({ page }) => {
        await page.goto('/dashboard/home');
        await page.keyboard.press('Tab');

        const skip = page.getByRole('link', { name: 'Skip to main content' });
        await expect(skip).toBeFocused();
        await expect(skip).toBeVisible();

        await page.keyboard.press('Enter');
        await expect(page.locator('#main')).toBeFocused();
    });

    test('the tabs work from the keyboard, and the new page title takes focus', async ({ page }) => {
        await page.goto('/dashboard/home');
        await expect(page).toHaveTitle('Home · Kaduna eDQA');

        const monitor = page.getByRole('navigation', { name: 'Dashboard pages' }).getByRole('link', { name: 'Monitor' });
        await tabTo(page, monitor);
        await page.keyboard.press('Enter');

        await expect(page).toHaveURL(/\/dashboard\/monitor$/);
        await expect(page.getByRole('heading', { level: 1, name: 'Monitor' })).toBeFocused();
        await expect(page).toHaveTitle('Monitor · Kaduna eDQA');
        await expect(monitor).toHaveAttribute('aria-current', 'page');
    });

    test('a filter change keeps focus on the select, updates the URL and is announced', async ({ page }) => {
        await page.goto('/dashboard/lga');
        const year = page.getByLabel('Year');
        await tabTo(page, year);
        const firstYear = (await year.locator('option').nth(1).getAttribute('value')) ?? '';
        expect(firstYear).not.toBe('');

        await year.selectOption(firstYear);

        await expect(page).toHaveURL(new RegExp(`/dashboard/lga\\?year=${firstYear}$`));
        await expect(year).toBeFocused();
        await expect(page.locator('[aria-live="polite"][aria-atomic="true"]')).toContainText(/Showing [\d,]+ facilities\. Average|No facilities match/);
        await expect(page.getByRole('link', { name: 'Clear filters' })).toBeVisible();
    });

    test('an invalid filter falls back to all, at the cleaned URL', async ({ page }) => {
        await page.goto('/dashboard/home?year=1999&lga=nowhere');

        await expect(page).toHaveURL(/\/dashboard\/home$/);
        await expect(page.getByLabel('Year')).toHaveValue('');
    });

    test('the empty shell has no serious or critical axe violations', async ({ page }) => {
        test.setTimeout(300_000); // three pages, each waited on and scanned
        for (const path of ['/dashboard/home', '/dashboard/lga', '/admin']) {
            await page.goto(path);
            // Ready: the title is up and no deferred block is still loading.
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
            await expect(page.locator('[aria-busy="true"]')).toHaveCount(0, { timeout: 60_000 }); // deferred data and the lazy chart, on a busy machine
            await checkA11y(page);
        }
    });
});
