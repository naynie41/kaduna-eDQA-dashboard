import { expect, test, type Page } from '@playwright/test';
import { checkA11y } from './support/a11y';

// The public layout (ARCHITECTURE.md §9.0–9.1, D-76–D-78), on every dashboard page at the project's
// width (desktop 1280, phone 360). A visitor: no admin preview bar, no link to /login or /admin,
// axe clean. An administrator: the preview bar, and the open round can be chosen.

const PAGES = [
    { path: '/', heading: 'Home' },
    { path: '/facilities', heading: 'Facility view' },
    { path: '/lgas', heading: 'LGA view' },
    { path: '/monitor', heading: 'Monitor' },
    { path: '/visuals', heading: 'Visuals' },
    { path: '/plan', heading: 'Implementation plan' },
];

async function ready(page: Page, heading: string): Promise<void> {
    await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible({ timeout: 60_000 });
    await expect(page.locator('[aria-busy="true"]')).toHaveCount(0, { timeout: 60_000 });
}

test.describe('As a visitor', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    for (const { path, heading } of PAGES) {
        test(`${heading}: no admin bar, no way to /login or /admin, axe clean`, async ({ page }) => {
            await page.goto(path);
            await ready(page, heading);

            await expect(page.getByRole('region', { name: 'Admin preview' })).toHaveCount(0);
            await expect(page.getByRole('navigation', { name: 'Account' })).toHaveCount(0);
            const hrefs = await page.locator('a[href]').evaluateAll((links) => links.map((link) => link.getAttribute('href') ?? ''));
            expect(hrefs.filter((href) => /^\/(login|admin)(\/|$|\?)/.test(href))).toEqual([]);
            await expect(page.getByRole('contentinfo')).toContainText('Support: HSDF');
            await checkA11y(page);
        });
    }
});

test.describe('As an administrator', () => {
    test('the preview bar is on every public page, before the main content', async ({ page }) => {
        for (const { path, heading } of PAGES) {
            await page.goto(path);
            await ready(page, heading);
            const bar = page.getByRole('region', { name: 'Admin preview' });
            await expect(bar).toContainText('Admin preview — includes the open round');
            await expect(bar.getByRole('link', { name: 'Back to admin' })).toHaveAttribute('href', '/admin');
        }
        await checkA11y(page);
    });

    test('the open round can be chosen, where a visitor is sent back to the published figures', async ({ page, browser }) => {
        const rounds = await page.request.get('/admin/api/rounds').then(async (r) => ((await r.json()) as { data: { year: number; quarter: number; status: string }[] }).data);
        const open = rounds.find((round) => round.status === 'open');
        if (open === undefined) {
            test.skip(true, 'No open round in this database');

            return;
        }
        const query = `?year=${open.year}&quarter=${open.quarter}`;

        // The administrator stays on the open round.
        await page.goto(`/${query}`);
        await ready(page, 'Home');
        // Inertia may reorder the keys; both must still be there.
        await expect(page).toHaveURL(new RegExp(`[?&]year=${open.year}(&|$)`));
        await expect(page).toHaveURL(new RegExp(`[?&]quarter=${open.quarter}(&|$)`));

        // A visitor asking for it is sent to the clean URL (the latest closed round, D-77).
        const visitor = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const anonymous = await visitor.newPage();
        await anonymous.goto(`/${query}`);
        await expect(anonymous.getByRole('heading', { level: 1, name: 'Home' })).toBeVisible({ timeout: 60_000 });
        await expect(anonymous).not.toHaveURL(/quarter=/);
        await visitor.close();
    });
});
