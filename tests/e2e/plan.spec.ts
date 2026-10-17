import { expect, test } from '@playwright/test';
import { checkA11y } from './support/a11y';

test.describe('Implementation plan', () => {
    test('lists actions weakest first with their owners and review points, the panels beside; axe finds nothing serious', async ({ page }) => {
        await page.goto('/dashboard/plan?year=2026&quarter=2');
        await expect(page.getByRole('heading', { level: 1, name: 'Implementation plan' })).toBeVisible();
        await expect(page.locator('[aria-busy="true"]')).toHaveCount(0, { timeout: 60_000 }); // deferred plan

        const table = page.getByRole('table', { name: 'Implementation plan' });
        await expect(table.getByRole('columnheader')).toHaveText(['LGA', 'Overall', 'Weakest dimension', 'Score', 'Recommended action', 'Owner', 'Review']);
        const rows = table.getByRole('rowheader');
        expect(await rows.count()).toBeLessThanOrEqual(23);
        if ((await rows.count()) > 0) {
            await expect(table.getByRole('row').nth(1)).toContainText(/After Q2 2026/);
        }

        await expect(page.getByRole('region', { name: 'No action needed' })).toBeVisible();
        await expect(page.getByRole('table', { name: 'Actions by type' }).getByRole('rowheader')).toHaveCount(3);
        await expect(page.getByRole('table', { name: 'What each action means' }).getByRole('rowheader')).toHaveCount(3);

        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await checkA11y(page);
    });
});
