import { expect, test, type Locator, type Page } from '@playwright/test';
import { checkA11y } from './support/a11y';

// The Data sources page (ARCHITECTURE.md §9.3). Two set-ups:
// - `make e2e`: the dev stack has no source settings, so each panel explains what is missing;
// - `make e2e-sources`: the app and worker point at both mocks (compose.e2e-sources.yml,
//   E2E_SOURCES=1), seeded with a few visits recorded on both tools, so Pull all runs end to end
//   and one of the two copies of a visit is quarantined as DUPLICATE_ASSESSMENT.
// Every viewport project (1280 and 360) runs the axe checks; the pull itself runs once, on
// desktop, since both projects share the one stack.
const againstMocks = process.env.E2E_SOURCES === '1';

async function ready(page: Page): Promise<void> {
    await expect(page.getByRole('heading', { level: 1, name: 'Data sources' })).toBeVisible({ timeout: 60_000 });
}

/** Tab from the top of the page until the target has focus: no mouse. */
async function tabTo(page: Page, target: Locator, max = 250): Promise<void> {
    await page.locator('body').focus();
    for (let i = 0; i < max; i += 1) {
        await page.keyboard.press('Tab');
        if (await target.evaluate((el) => el === document.activeElement).catch(() => false)) {
            return;
        }
    }
    throw new Error('The target was never reached with Tab.');
}

test.describe('Data sources page, no source configured', () => {
    test.skip(againstMocks, 'make e2e-sources configures both mocks');

    test('says what is missing for each source, with no errors', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('console', (message) => {
            if (message.type() === 'error') {
                errors.push(message.text());
            }
        });
        await page.goto('/admin/sources');
        await ready(page);

        for (const name of ['ODK Central', 'KoboToolbox']) {
            const panel = page.getByRole('region', { name, exact: true });
            await expect(panel).toContainText('Not configured');
        }
        await expect(page.getByRole('region', { name: 'ODK Central', exact: true }).getByText('ODK_CENTRAL_URL')).toBeVisible();
        await expect(page.getByRole('region', { name: 'KoboToolbox', exact: true }).getByText('KOBO_API_TOKEN')).toBeVisible();
        await expect(page.getByRole('button', { name: /^Pull now/ })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Pull all' })).toHaveCount(0);
        await expect(page.getByRole('region', { name: 'Pull history' })).toBeVisible();
        await expect(page.getByRole('region', { name: 'Deletions at source' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Regenerate facility media file' })).toBeVisible();
        expect(errors).toEqual([]);
    });

    test('the old ODK pull address leads here', async ({ page }) => {
        await page.goto('/admin/odk');
        await ready(page);
        await expect(page).toHaveURL(/\/admin\/sources$/);
    });

    test('no serious or critical axe violations', async ({ page }) => {
        await page.goto('/admin/sources');
        await ready(page);
        await checkA11y(page);
    });
});

test.describe('Data sources page, against both mocks', () => {
    test.skip(!againstMocks, 'Run with make e2e-sources');
    test.describe.configure({ mode: 'serial' }); // one pull at a time on the one stack

    test('Pull all, keyboard only: both sources pulled to the end, then the history names both', async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== 'desktop', 'Pulled once, from the desktop project');
        test.setTimeout(300_000);
        await page.goto('/admin/sources');
        await ready(page);
        for (const name of ['ODK Central', 'KoboToolbox']) {
            await expect(page.getByRole('region', { name, exact: true })).toContainText('Connected');
        }

        const button = page.getByRole('button', { name: 'Pull all' });
        await tabTo(page, button);
        await page.keyboard.press('Enter');
        await expect(page.getByRole('status').filter({ hasText: /^Pulls started: / })).toBeVisible();

        for (const name of ['ODK Central', 'KoboToolbox']) {
            const progress = page.getByRole('region', { name: `Pull from ${name}`, exact: true });
            await expect(progress).toBeVisible();
            await expect(progress.getByText('Finished')).toBeVisible({ timeout: 240_000 });
            await expect(progress.getByRole('term').filter({ hasText: 'Fetched' })).toBeVisible();
        }

        const history = page.getByRole('region', { name: 'Pull history' });
        await expect(history.getByRole('row').filter({ hasText: 'ODK Central' }).filter({ hasText: 'Succeeded' }).first()).toBeVisible();
        await expect(history.getByRole('row').filter({ hasText: 'KoboToolbox' }).filter({ hasText: 'Succeeded' }).first()).toBeVisible();
        await expect(history.getByRole('row').nth(1)).toContainText('E2E Administrator');
    });

    test('the history source filter is reached and used by keyboard', async ({ page }) => {
        await page.goto('/admin/sources');
        await ready(page);
        const filter = page.getByRole('region', { name: 'Pull history' }).getByLabel('Source', { exact: true });
        await tabTo(page, filter);
        await page.keyboard.press('ArrowDown'); // native select: the first source is chosen at once
        await expect(page).toHaveURL(/source=odk/, { timeout: 30_000 });
        const rows = page.getByRole('region', { name: 'Pull history' }).getByRole('row');
        await expect(rows.nth(1)).toContainText('ODK Central');
        await expect(rows.filter({ hasText: 'KoboToolbox' })).toHaveCount(0);
    });

    test('the visit recorded on both tools is in Data issues, showing both sources', async ({ page }) => {
        await page.goto('/admin/data-issues?rule=DUPLICATE_ASSESSMENT');
        await expect(page.getByRole('heading', { level: 1, name: 'Data issues' })).toBeVisible({ timeout: 60_000 });

        const sides = page.locator('dl[aria-label="Both sides of the duplicate"]');
        const both = sides.filter({ hasText: 'ODK Central' }).filter({ hasText: 'KoboToolbox' }).first();
        await expect(both).toBeVisible();
        await expect(both).toContainText('Already accepted');
        await expect(both).toContainText('This submission');
    });

    test('the dashboard shows assessments from both sources (Monitor, by source)', async ({ page }) => {
        // The visits are in the round the mocks were seeded for: E2E_ROUND (year and quarter, "2026Q3"), else the latest.
        const [year, quarter] = (process.env.E2E_ROUND ?? '').split('Q');
        await page.goto(year && quarter ? `/dashboard/monitor?year=${year}&quarter=${quarter}` : '/dashboard/monitor');
        const panel = page.getByRole('region', { name: 'Assessments by source' });
        await expect(panel).toBeVisible({ timeout: 60_000 });

        for (const name of ['ODK Central', 'KoboToolbox']) {
            const row = panel.getByRole('row').filter({ has: page.getByRole('rowheader', { name, exact: true }) });
            await expect(row).toHaveCount(1);
            await expect(row.getByRole('cell')).not.toHaveText('0');
        }
    });

    test('GET /api/quarantine holds the visit recorded on both tools, its detail naming both sources', async ({ page }) => {
        await page.goto('/admin/sources'); // the signed-in session, for the API request
        const response = await page.request.get('/api/quarantine?rule=DUPLICATE_ASSESSMENT&status=open', { headers: { Accept: 'application/json' } });
        expect(response.status()).toBe(200);

        const body = (await response.json()) as { data: { failures: { code: string; detail: string }[] }[] };
        const details = body.data.flatMap((record) => record.failures.filter((failure) => failure.code === 'DUPLICATE_ASSESSMENT').map((failure) => failure.detail));
        expect(details.some((detail) => detail.includes('ODK Central') && detail.includes('KoboToolbox'))).toBe(true);
    });

    test('no serious or critical axe violations, with both sources pulled', async ({ page }) => {
        await page.goto('/admin/sources');
        await ready(page);
        await checkA11y(page);
    });

    test('no serious or critical axe violations on Data issues, the duplicate shown', async ({ page }) => {
        await page.goto('/admin/data-issues?rule=DUPLICATE_ASSESSMENT');
        await expect(page.getByRole('heading', { level: 1, name: 'Data issues' })).toBeVisible({ timeout: 60_000 });
        await checkA11y(page);
    });
});
