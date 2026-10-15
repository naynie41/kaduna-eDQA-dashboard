import { expect, test, type Page } from '@playwright/test';
import { checkA11y } from './support/a11y';

async function ready(page: Page): Promise<void> {
    await expect(page.getByRole('heading', { level: 1, name: 'Home' })).toBeVisible();
    await expect(page.locator('[aria-busy="true"]')).toHaveCount(0, { timeout: 60_000 }); // deferred data and the lazy chart, on a busy machine
}

test.describe('Home', () => {
    test('the hero reads as terms and values, the dimensions as a list', async ({ page }) => {
        await page.goto('/dashboard/home?year=2026&quarter=2');
        await ready(page);

        const average = page.getByRole('term').filter({ hasText: 'Average quality score' });
        await expect(average).toBeVisible();
        await expect(page.getByRole('definition').first()).toHaveText(/^\d+/);
        for (const term of ['Facilities visited', 'Local governments', 'Facilities scoring 90+', 'Facilities under 70']) {
            await expect(page.getByRole('term').filter({ hasText: term })).toBeVisible();
        }

        const dimensions = page.getByRole('region', { name: 'The three dimensions' });
        await expect(dimensions.getByRole('listitem')).toHaveCount(4);
        await expect(dimensions).toContainText('The mark on each bar is the Strong threshold of 90.');
        await expect(dimensions).toContainText(/is the weakest dimension, at \d/);

        await expect(page.getByRole('heading', { name: 'Public facilities', exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Private facilities', exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Weakest local governments' })).toBeVisible();
    });

    test('the trend shows every round whatever the quarter, and its table says so', async ({ page }) => {
        await page.goto('/dashboard/home?year=2026&quarter=2');
        await ready(page);

        const figure = page.getByRole('figure', { name: 'Quality score by quarter' });
        await expect(figure.getByRole('img', { name: /Line chart of the overall quality score/ })).toBeVisible();
        await figure.getByRole('button', { name: 'Show as table' }).click();

        const rows = figure.getByRole('row');
        await expect(rows).toHaveCount(5); // header + four demo rounds
        await expect(figure.getByRole('rowheader', { name: /Q2 2026 \(in view\)/ })).toBeVisible();
        await expect(figure.getByRole('rowheader', { name: 'Q3 2025' })).toBeVisible();
    });

    test('an LGA filter turns the weakest list into its wards', async ({ page }) => {
        await page.goto('/dashboard/home');
        await ready(page);
        const lga = page.getByLabel('LGA');
        const name = (await lga.locator('option').nth(1).textContent()) ?? '';
        await lga.selectOption({ index: 1 });

        await expect(page.getByRole('heading', { name: `Weakest wards in ${name}` })).toBeVisible({ timeout: 60_000 }); // a deferred reload
    });

    test('no serious or critical axe violations with the page loaded', async ({ page }) => {
        await page.goto('/dashboard/home');
        await ready(page);
        await checkA11y(page);

        await page.getByRole('figure', { name: 'Quality score by quarter' }).getByRole('button', { name: 'Show as table' }).click();
        await checkA11y(page);
    });
});
