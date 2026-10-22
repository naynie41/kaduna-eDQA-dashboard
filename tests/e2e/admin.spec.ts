import { expect, test, type Page } from '@playwright/test';
import { checkA11y } from './support/a11y';

// The administration shell (ARCHITECTURE.md §9.3, D-55): /admin opens the ODK pull page; the five
// pages share the admin tabs (no dashboard filters) and keep the ODK status bar.

const PAGES = [
    { path: '/admin/odk', heading: 'ODK pull' },
    { path: '/admin/data-issues', heading: 'Data issues' },
    { path: '/admin/rounds', heading: 'Rounds' },
    { path: '/admin/facilities', heading: 'Facility list' },
    { path: '/admin/scoring-rules', heading: 'Scoring rules' },
];

async function ready(page: Page, heading: string): Promise<void> {
    await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible({ timeout: 60_000 });
}

test.describe('Administration shell', () => {
    test('/admin opens the ODK pull page, from the rail', async ({ page }) => {
        await page.goto('/dashboard/home');
        await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Administration' }).click();

        await ready(page, 'ODK pull');
        await expect(page).toHaveURL(/\/admin\/odk$/);
        await expect(page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Administration' })).toHaveAttribute('aria-current', 'page');
    });

    test('the tabs reach every page, the dashboard filters are gone and the status bar stays', async ({ page }) => {
        await page.goto('/admin/odk');
        await ready(page, 'ODK pull');
        const tabs = page.getByRole('navigation', { name: 'Administration pages' });

        await expect(tabs.getByRole('link')).toHaveCount(5);
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
