import { expect, test, type Page } from '@playwright/test';
import { checkA11y } from './support/a11y';

async function ready(page: Page): Promise<void> {
    await expect(page.getByRole('heading', { level: 1, name: 'Facility view' })).toBeVisible();
    await expect(page.locator('[aria-busy="true"]')).toHaveCount(0, { timeout: 60_000 }); // deferred data
}

test.describe('Facility view', () => {
    test('lists weakest first; sorting from the keyboard reloads the order, updates the URL and is announced', async ({ page }) => {
        await page.goto('/dashboard/facility?year=2026&quarter=2');
        await ready(page);

        const table = page.getByRole('table', { name: /Facilities in view, by Overall, lowest first/ });
        await expect(table.getByRole('columnheader', { name: 'April' })).toBeVisible();
        await expect(page.getByText(/^Showing 1–50 of [\d,]+ facilities$/)).toBeVisible();

        const lga = table.getByRole('button', { name: 'LGA' });
        await lga.focus();
        await page.keyboard.press('Enter');

        await expect(page).toHaveURL(/[?&]sort=lga(&|$)/, { timeout: 60_000 }); // Inertia sorts the keys
        await expect(page).toHaveURL(/[?&]dir=asc(&|$)/);
        const sorted = page.getByRole('table', { name: /Facilities in view, by LGA, A to Z/ });
        await expect(sorted.getByRole('columnheader', { name: /^LGA/ })).toHaveAttribute('aria-sort', 'ascending');
        await expect(page.locator('[aria-live="polite"][aria-atomic="true"]')).toContainText(/Sorted by LGA, A to Z\. Showing 1 to 50 of [\d,]+ facilities\./);
        await expect(sorted.getByRole('button', { name: 'LGA' })).toBeFocused();
        await expect(sorted.getByRole('rowheader').first()).toBeVisible();
    });

    test('the measure switcher and the pages work, the table caption following', async ({ page }) => {
        await page.goto('/dashboard/facility?year=2026&quarter=2');
        await ready(page);

        await page.getByRole('group', { name: 'Measure' }).getByRole('button', { name: 'Consistency' }).click();
        await expect(page).toHaveURL(/metric=consistency/, { timeout: 60_000 });
        await expect(page.getByRole('table', { name: /by Consistency, lowest first/ })).toBeVisible();

        await page.getByRole('navigation', { name: 'Facility pages' }).getByRole('button', { name: 'Next' }).click();
        await expect(page).toHaveURL(/page=2/, { timeout: 60_000 });
        await expect(page.getByText(/^Showing 51–100 of [\d,]+ facilities$/)).toBeVisible();
    });

    test('the table scrolls in a focusable region and axe finds nothing serious', async ({ page }) => {
        await page.goto('/dashboard/facility');
        await ready(page);

        const region = page.getByRole('region', { name: /Facilities in view.*scrollable/ });
        await region.focus();
        await expect(region).toBeFocused();
        await checkA11y(page);
    });
});
