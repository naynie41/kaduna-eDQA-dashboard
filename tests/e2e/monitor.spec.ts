import { expect, test } from '@playwright/test';
import { checkA11y } from './support/a11y';

test.describe('Monitor', () => {
    test('shows the owner split, the ranked coverage lists with the mean in words, and the window table; axe finds nothing serious', async ({ page }) => {
        await page.goto('/dashboard/monitor?year=2026&quarter=2');
        await expect(page.getByRole('heading', { level: 1, name: 'Monitor' })).toBeVisible();
        await expect(page.locator('[aria-busy="true"]')).toHaveCount(0, { timeout: 60_000 }); // deferred blocks

        await expect(page.getByRole('region', { name: 'Public and private facilities' })).toBeVisible();

        const lgas = page.getByRole('list', { name: 'Facilities visited, by local government' });
        const rows = lgas.getByRole('listitem');
        expect(await rows.count()).toBeGreaterThan(0);
        expect(await rows.count()).toBeLessThanOrEqual(23);
        await expect(rows.first()).toContainText(/\d+ facilities visited/);
        await expect(page.getByText(/^State mean: [\d.,]+ facilities visited per local government$/)).toBeVisible();

        await expect(page.getByRole('list', { name: 'Facilities visited, by level' }).getByRole('listitem').first()).toContainText('Primary');

        const windowTable = page.getByRole('table', { name: 'Assessment window' });
        await expect(windowTable.getByRole('rowheader', { name: 'Q2 2026' })).toBeVisible();
        await expect(windowTable.getByRole('columnheader', { name: 'Assessments recorded' })).toBeVisible();

        // WCAG 1.4.10 reflow: the table scrolls in its own region, the page never sideways.
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

        await checkA11y(page);
    });
});
