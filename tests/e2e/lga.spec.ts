import { expect, test, type Page } from '@playwright/test';
import { checkA11y } from './support/a11y';

async function ready(page: Page): Promise<void> {
    await expect(page.getByRole('heading', { level: 1, name: 'LGA view' })).toBeVisible();
    await expect(page.locator('[aria-busy="true"]')).toHaveCount(0, { timeout: 60_000 }); // deferred data
}

test.describe('LGA view', () => {
    test('a tile toggles the LGA filter from the keyboard, the URL follows and focus stays on it', async ({ page }) => {
        await page.goto('/dashboard/lga?year=2026&quarter=2');
        await ready(page);

        const grid = page.getByRole('group', { name: 'Filter by local government' });
        const tiles = grid.getByRole('button');
        expect(await tiles.count()).toBeLessThanOrEqual(23);
        const first = tiles.first();
        const label = (await first.getAttribute('aria-label')) ?? '';
        expect(label).toMatch(/^.+, [\d.]+, (Strong|Acceptable|Review|Needs action), [\d,]+ facilities$/);
        const name = label.split(',')[0] ?? '';

        await first.focus();
        await page.keyboard.press('Enter');

        await expect(page).toHaveURL(/lga=[A-Z]+/, { timeout: 60_000 });
        const pressed = grid.getByRole('button', { name: new RegExp(`^${name},`), pressed: true });
        await expect(pressed).toBeVisible();
        await expect(pressed).toBeFocused();
        await expect(page.getByRole('table', { name: new RegExp(`${name}`) })).toBeVisible();

        await page.keyboard.press('Enter'); // and back off
        await expect(page).not.toHaveURL(/lga=/, { timeout: 60_000 });
        await expect(grid.getByRole('button', { name: new RegExp(`^${name},`), pressed: false })).toBeFocused();
    });

    test('the table names months for one quarter, and sorts with aria-sort', async ({ page }) => {
        await page.goto('/dashboard/lga?year=2026&quarter=2');
        await ready(page);

        const table = page.getByRole('table', { name: /All four reports by local government: Q2 2026/ });
        await expect(table.getByRole('columnheader', { name: 'April' })).toBeVisible();
        await expect(table.getByRole('columnheader', { name: /Overall/ })).toHaveAttribute('aria-sort', 'descending');

        await table.getByRole('button', { name: 'LGA' }).click();
        await expect(table.getByRole('columnheader', { name: /^LGA/ })).toHaveAttribute('aria-sort', 'ascending');
        await expect(table.getByRole('rowheader').first()).toHaveText('Birnin Gwari');
    });

    test('no serious or critical axe violations', async ({ page }) => {
        await page.goto('/dashboard/lga');
        await ready(page);
        await checkA11y(page);
    });
});
