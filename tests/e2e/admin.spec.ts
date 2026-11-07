import { expect, test, type Page } from '@playwright/test';
import { checkA11y } from './support/a11y';

// The admin layout (ARCHITECTURE.md §9.0–9.1, §9.3): /admin opens the Data sources page; the rail
// lists the five pages and "View public dashboard" (no dashboard filters), and the sources' status
// bar stays.

const PAGES = [
    { path: '/admin/sources', heading: 'Data sources' },
    { path: '/admin/data-issues', heading: 'Data issues' },
    { path: '/admin/rounds', heading: 'Rounds' },
    { path: '/admin/facilities', heading: 'Facility list' },
    { path: '/admin/scoring-rules', heading: 'Scoring rules' },
];

async function ready(page: Page, heading: string): Promise<void> {
    await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible({ timeout: 60_000 });
}

test.describe('Administration shell', () => {
    test('/admin opens the Data sources page, from the admin preview of the public site', async ({ page }) => {
        await page.goto('/');
        await page.getByRole('region', { name: 'Admin preview' }).getByRole('link', { name: 'Back to admin' }).click();

        await ready(page, 'Data sources');
        await expect(page).toHaveURL(/\/admin\/sources$/);
        await expect(page.getByRole('navigation', { name: 'Administration pages' }).getByRole('link', { name: 'Data sources' })).toHaveAttribute('aria-current', 'page');
    });

    test('the rail leads back to the public dashboard', async ({ page }) => {
        await page.goto('/admin/sources');
        await ready(page, 'Data sources');
        await page.getByRole('navigation', { name: 'Administration pages' }).getByRole('link', { name: 'View public dashboard' }).click();

        await expect(page.getByRole('heading', { level: 1, name: 'Home' })).toBeVisible({ timeout: 60_000 });
        await expect(page.getByRole('region', { name: 'Admin preview' })).toBeVisible();
    });

    test('the rail reaches every page, the dashboard filters are gone and the status bar stays', async ({ page }) => {
        await page.goto('/admin/sources');
        await ready(page, 'Data sources');
        const tabs = page.getByRole('navigation', { name: 'Administration pages' });

        await expect(tabs.getByRole('link')).toHaveCount(6); // five pages and the public dashboard
        await expect(tabs.getByRole('link', { name: /^Data issues, \d+ open$/ })).toBeVisible();
        await expect(page.getByLabel('Year')).toHaveCount(0); // the dashboard's filter bar
        await expect(page.getByRole('status').filter({ hasText: /ODK Central|pull/ })).toBeVisible();

        for (const { path, heading } of PAGES.slice(1)) {
            await tabs.getByRole('link', { name: new RegExp(`^${heading}`) }).click();
            await ready(page, heading);
            await expect(page).toHaveURL(new RegExp(`${path}$`));
            await expect(tabs.getByRole('link', { name: new RegExp(`^${heading}`) })).toHaveAttribute('aria-current', 'page');
        }
    });

    for (const { path, heading } of PAGES) {
        test(`${heading}: no serious or critical axe violations in the empty shell`, async ({ page }) => {
            await page.goto(path);
            await ready(page, heading);
            await checkA11y(page);
        });
    }
});
