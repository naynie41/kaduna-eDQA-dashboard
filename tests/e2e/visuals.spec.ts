import { expect, test, type Page } from '@playwright/test';
import { checkA11y } from './support/a11y';

async function ready(page: Page, path: string): Promise<void> {
    await page.goto(path);
    await expect(page.getByRole('heading', { level: 1, name: 'Visuals' })).toBeVisible();
    await expect(page.locator('[aria-busy="true"]')).toHaveCount(0, { timeout: 60_000 }); // deferred blocks
    await expect(page.getByRole('img', { name: /grouped bars/ })).toHaveCount(3, { timeout: 60_000 }); // the lazy charts
}

test.describe('Visuals', () => {
    test('three month charts and four rankings, the page never scrolls sideways; axe finds nothing serious', async ({ page }) => {
        await ready(page, '/dashboard/visuals?year=2026&quarter=2');

        for (const month of ['April 2026', 'May 2026', 'June 2026']) {
            const figure = page.getByRole('figure', { name: month });
            await expect(figure).toContainText(/overall [\d.]+/);
            await expect(figure.getByRole('list', { name: 'Series' }).getByRole('listitem')).toHaveText(['Availability', 'Consistency', 'Validity', 'Overall']);
        }
        for (const measure of ['Overall', 'Availability', 'Consistency', 'Validity']) {
            const list = page.getByRole('list', { name: `${measure}, by local government` });
            expect(await list.getByRole('listitem').count()).toBeLessThanOrEqual(23);
            await expect(list.getByRole('listitem').first()).toContainText(/\d+\.\d(Strong|Acceptable|Review|Needs action)/);
        }

        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await checkA11y(page);
    });

    test('"Show as table" works from the keyboard and back', async ({ page }) => {
        await ready(page, '/dashboard/visuals?year=2026&quarter=2');

        const figure = page.getByRole('figure', { name: 'April 2026' });
        const toggle = figure.getByRole('button', { name: 'Show as table' });
        await toggle.focus();
        await page.keyboard.press('Enter');

        const table = figure.getByRole('table', { name: 'April 2026' });
        await expect(table.getByRole('rowheader', { name: 'April (Q2 2026)' })).toBeVisible();
        await expect(figure.getByRole('button', { name: 'Show as chart' })).toBeFocused();
        await checkA11y(page);

        await page.keyboard.press('Space');
        await expect(figure.getByRole('img', { name: /grouped bars/ })).toBeVisible({ timeout: 30_000 });
    });

    test('the Overall / Public / Private view reloads the blocks, and locks to the owner filter', async ({ page }) => {
        await ready(page, '/dashboard/visuals?year=2026&quarter=2');

        await page.getByRole('group', { name: 'Facilities shown' }).getByRole('button', { name: 'Private' }).click();
        await expect(page).toHaveURL(/[?&]owner_view=private(&|$)/, { timeout: 60_000 });
        await expect(page.getByRole('heading', { name: 'Scores by local government: private facilities' })).toBeVisible();
        await expect(page.locator('[aria-live="polite"][aria-atomic="true"]')).toContainText('Showing Private.');

        await ready(page, '/dashboard/visuals?year=2026&quarter=2&owner=public');
        const group = page.getByRole('group', { name: 'Facilities shown' });
        await expect(group).toHaveAccessibleDescription(/owner filter above is set to Public facilities/);
        await expect(group.getByRole('button', { name: 'Public' })).toHaveAttribute('aria-pressed', 'true');
        await expect(group.getByRole('button', { name: 'Overall' })).toHaveAttribute('aria-disabled', 'true');
    });
});
