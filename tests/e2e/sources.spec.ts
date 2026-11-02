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
        test.setTimeout(360_000);
        const sides = page.getByRole('table', { name: 'Both sides of the duplicate' });
        const both = sides.filter({ hasText: 'ODK Central' }).filter({ hasText: 'KoboToolbox' }).first();
        // The phone project runs beside the desktop one, whose Pull all brings the duplicate in:
        // look again (a fresh page each time) until it has, rather than fail on the first look.
        await expect(async () => {
            await page.goto('/admin/data-issues?rule=DUPLICATE_ASSESSMENT');
            await expect(page.getByRole('heading', { level: 1, name: 'Data issues' })).toBeVisible({ timeout: 60_000 });
            await expect(both).toBeVisible({ timeout: 5_000 });
        }).toPass({ timeout: 300_000, intervals: [5_000, 10_000] });
        await expect(both).toContainText('Already accepted');
        await expect(both).toContainText('This submission');
    });

    test('the dashboard shows assessments from both sources (Monitor, by source)', async ({ page }) => {
        // The visits are in the round the mocks were seeded for: E2E_ROUND (year and quarter, "2026Q3"), else the latest.
        test.setTimeout(360_000);
        const [year, quarter] = (process.env.E2E_ROUND ?? '').split('Q');
        const panel = page.getByRole('region', { name: 'Assessments by source' });
        // As above: the desktop project's Pull all may still be bringing the assessments in.
        await expect(async () => {
            await page.goto(year && quarter ? `/dashboard/monitor?year=${year}&quarter=${quarter}` : '/dashboard/monitor');
            await expect(panel).toBeVisible({ timeout: 60_000 });
            for (const name of ['ODK Central', 'KoboToolbox']) {
                const row = panel.getByRole('row').filter({ has: page.getByRole('rowheader', { name, exact: true }) });
                await expect(row).toHaveCount(1, { timeout: 5_000 });
                await expect(row.getByRole('cell')).not.toHaveText('0', { timeout: 5_000 });
            }
        }).toPass({ timeout: 300_000, intervals: [5_000, 10_000] });
    });

    test('GET /api/quarantine holds the visit recorded on both tools, its detail naming both sources', async ({ page }) => {
        test.setTimeout(360_000);
        await page.goto('/admin/sources'); // the signed-in session, for the API request
        // As above: wait for the desktop project's Pull all rather than fail on the first look.
        await expect(async () => {
            const response = await page.request.get('/api/quarantine?rule=DUPLICATE_ASSESSMENT&status=open', { headers: { Accept: 'application/json' } });
            expect(response.status()).toBe(200);
            const body = (await response.json()) as { data: { failures: { code: string; detail: string }[] }[] };
            const details = body.data.flatMap((record) => record.failures.filter((failure) => failure.code === 'DUPLICATE_ASSESSMENT').map((failure) => failure.detail));
            expect(details.some((detail) => detail.includes('ODK Central') && detail.includes('KoboToolbox'))).toBe(true);
        }).toPass({ timeout: 300_000, intervals: [5_000, 10_000] });
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

    // Last, and once (desktop): it resolves one of the duplicates the tests above look at.
    test('resolves a cross-source duplicate with "Use this one instead", keyboard only', async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== 'desktop', 'Resolved once, from the desktop project');
        test.setTimeout(360_000);
        const comparison = page.getByRole('table', { name: 'Both sides of the duplicate' }).filter({ hasText: 'ODK Central' }).filter({ hasText: 'KoboToolbox' }).first();
        await expect(async () => {
            await page.goto('/admin/data-issues?rule=DUPLICATE_ASSESSMENT');
            await expect(comparison).toBeVisible({ timeout: 5_000 });
        }).toPass({ timeout: 300_000, intervals: [5_000, 10_000] });
        // Both sides compared: who assessed, and the overall each scores.
        await expect(comparison.getByRole('rowheader', { name: 'Overall it scores' })).toBeVisible();
        const before = await page.getByRole('table', { name: 'Both sides of the duplicate' }).count();

        await tabTo(page, page.getByRole('button', { name: 'Use this one instead' }).first());
        await page.keyboard.press('Enter');
        const dialog = page.getByRole('dialog', { name: 'Use this submission instead?' });
        await expect(dialog).toBeVisible();
        await page.keyboard.type('The Kobo copy is the one the supervisor checked');
        await tabTo(page, dialog.getByRole('button', { name: 'Use this one instead' }));
        await page.keyboard.press('Enter');

        await expect(page.getByRole('status').filter({ hasText: 'This submission now counts in place of the earlier one.' })).toBeVisible();
        await expect(page.getByRole('status').filter({ hasText: /A rescore of Q\d \d{4} is queued/ })).toBeVisible();
        await expect(page.getByRole('table', { name: 'Both sides of the duplicate' })).toHaveCount(before - 1);
    });
});

// `make e2e-sources`, third pass: one accepted KoboToolbox visit is taken off the Kobo mock
// (tests/e2e/support/kobo-mock-delete.php) and `edqa:reconcile kobo` finds it deleted at source;
// the page is read with E2E_KOBO_DELETED=1. The target puts the visit back afterwards.
test.describe('Data issues, an assessment deleted at source', () => {
    test.skip(process.env.E2E_KOBO_DELETED !== '1', 'Run with make e2e-sources');
    test.describe.configure({ mode: 'serial' });

    /** KoboToolbox's assessment count on Monitor, in the round the mocks were seeded for. */
    async function koboCount(page: Page): Promise<number> {
        const [year, quarter] = (process.env.E2E_ROUND ?? '').split('Q');
        await page.goto(year && quarter ? `/dashboard/monitor?year=${year}&quarter=${quarter}` : '/dashboard/monitor');
        const row = page.getByRole('region', { name: 'Assessments by source' }).getByRole('row').filter({ has: page.getByRole('rowheader', { name: 'KoboToolbox', exact: true }) });
        await expect(row).toHaveCount(1, { timeout: 60_000 });

        return Number(((await row.getByRole('cell').first().textContent()) ?? '0').replace(/[^0-9]/g, ''));
    }

    test('withdraws it, keyboard only, and the dashboard count drops', async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== 'desktop', 'Withdrawn once, from the desktop project');
        test.setTimeout(360_000);
        const before = await koboCount(page);

        await page.goto('/admin/data-issues?tab=deleted');
        await expect(page.getByRole('heading', { level: 1, name: 'Data issues' })).toBeVisible({ timeout: 60_000 });
        const tab = page.getByRole('group', { name: 'Kind of issue' }).getByRole('button', { name: /Deleted at source \(\d+\)/ });
        await expect(tab).toHaveAttribute('aria-pressed', 'true');
        const withdraw = page.getByRole('button', { name: /^Withdraw the assessment of / }).first();
        await tabTo(page, withdraw);
        await page.keyboard.press('Enter');
        const dialog = page.getByRole('dialog', { name: 'Withdraw this assessment?' });
        await expect(dialog).toBeVisible();
        await page.keyboard.type('The LGA confirmed this visit never happened');
        await tabTo(page, dialog.getByRole('button', { name: 'Withdraw assessment' }));
        await page.keyboard.press('Enter');
        await expect(page.getByRole('status').filter({ hasText: 'Withdrawn: the assessment no longer counts.' })).toBeVisible();

        // The figures follow once the queued refresh has run.
        await expect(async () => expect(await koboCount(page)).toBe(before - 1)).toPass({ timeout: 120_000, intervals: [3_000, 5_000] });
    });

    test('no serious or critical axe violations on the Deleted at source tab', async ({ page }) => {
        await page.goto('/admin/data-issues?tab=deleted&state=resolved');
        await expect(page.getByRole('heading', { level: 1, name: 'Data issues' })).toBeVisible({ timeout: 60_000 });
        await checkA11y(page);
    });
});

// `make e2e-sources`, second pass: the Kobo mock's token is changed so the portal's is refused
// (HTTP 401), one Kobo pull fails on it, and the page is read with E2E_KOBO_AUTH_FAILED=1. The
// target puts the token back afterwards.
test.describe('Data sources page, KoboToolbox refusing the token', () => {
    test.skip(process.env.E2E_KOBO_AUTH_FAILED !== '1', 'Run with make e2e-sources');

    test('says the token was rejected, in words, and what that means for the next pulls', async ({ page }) => {
        await page.goto('/admin/sources');
        await ready(page);

        const kobo = page.getByRole('region', { name: 'KoboToolbox', exact: true });
        await expect(kobo).toContainText('Credentials rejected');
        await expect(kobo).toContainText(/KoboToolbox returned HTTP 401 at \d{2}:\d{2} WAT — the API token was rejected\./);
        await expect(kobo).toContainText('Every scheduled pull from KoboToolbox will fail the same way until the setting is fixed on the server.');
        // ODK Central is unaffected: one source's failure never hides the other's state.
        await expect(page.getByRole('region', { name: 'ODK Central', exact: true })).not.toContainText('Credentials rejected');
        // The token's value is never on the page.
        expect(await page.content()).not.toContain('mock-kobo-token');
    });

    test('reaches Kobo\'s Pull now by keyboard alone', async ({ page }) => {
        await page.goto('/admin/sources');
        await ready(page);
        await tabTo(page, page.getByRole('button', { name: 'Pull now KoboToolbox' }));
    });

    test('no serious or critical axe violations, with the error shown', async ({ page }) => {
        await page.goto('/admin/sources');
        await ready(page);
        await checkA11y(page);
    });
});
