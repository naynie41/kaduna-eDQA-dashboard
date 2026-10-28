import { expect, test, type Locator, type Page } from '@playwright/test';
import { checkA11y } from './support/a11y';

// The Data issues page (ARCHITECTURE.md §6, §9.3, D-57). Two set-ups:
// - `make e2e`: whatever the dev data holds; the page, its filters and axe;
// - `make e2e-issues`: `edqa:demo:data-issues` holds a null-LGA record and two ROUND_WINDOW
//   records through the real pipeline and passes them as E2E_ISSUES; every action is done with
//   the keyboard only.

type Held = { instance_id: string; record_id: number; facility_id: number; facility_code: string; lga_code: string; lga_name: string };
type Issues = { round_id: number; null_lga: Held; wrong_quarter: Held[] };

const issues: Issues | null = process.env.E2E_ISSUES ? (JSON.parse(process.env.E2E_ISSUES) as Issues) : null;

async function ready(page: Page): Promise<void> {
    await expect(page.getByRole('heading', { level: 1, name: 'Data issues' })).toBeVisible({ timeout: 60_000 });
    await expect(page.getByRole('region', { name: 'Rules', exact: true })).toBeVisible();
}

/** Press Tab from where focus is now until the target has it: no mouse. */
async function tabTo(page: Page, target: Locator, max = 250): Promise<void> {
    for (let i = 0; i < max; i += 1) {
        if (await target.evaluate((el) => el === document.activeElement).catch(() => false)) {
            return;
        }
        await page.keyboard.press('Tab');
    }
    throw new Error('The target was never reached with Tab.');
}

/** Tab from the top of the page. */
async function tabFromTop(page: Page, target: Locator): Promise<void> {
    await page.locator('body').focus();
    await tabTo(page, target);
}

/** The demo records; the describe that uses them is skipped without them. */
function demo(): Issues {
    if (issues === null) {
        throw new Error('E2E_ISSUES is not set: run make e2e-issues.');
    }

    return issues;
}

/** The two ROUND_WINDOW records. */
function wrongQuarter(): [Held, Held] {
    const [one, two] = demo().wrong_quarter;
    if (one === undefined || two === undefined) {
        throw new Error('E2E_ISSUES holds fewer than two ROUND_WINDOW records.');
    }

    return [one, two];
}

function row(page: Page, instance: string): Locator {
    return page.getByRole('row').filter({ has: page.getByRole('rowheader').filter({ hasText: instance }) });
}

test.describe('Data issues page', () => {
    test('rules, filters in the URL, and the hard and soft tabs, with no errors', async ({ page }) => {
        test.setTimeout(240_000); // every filter change is a page visit
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.goto('/admin/data-issues');
        await ready(page);

        await expect(page.getByRole('region', { name: 'Rules', exact: true }).getByRole('button')).toHaveCount(11); // 10 hard rules and RULE_ERROR
        await expect(page.getByLabel('Status', { exact: true })).toHaveValue('open');

        const lga = page.getByRole('button', { name: /Unknown LGA/ });
        await tabFromTop(page, lga);
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(/[?&]rule=LGA_UNKNOWN/);
        await expect(lga).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByLabel('Rule', { exact: true })).toHaveValue('LGA_UNKNOWN');
        await checkA11y(page); // a pressed rule too

        await page.getByLabel('Status', { exact: true }).focus();
        await page.keyboard.type('Rej'); // typeahead: just enough to pass Resolved
        await expect(page).toHaveURL(/[?&]state=rejected/);

        await page.goto('/admin/data-issues?tab=soft');
        await ready(page);
        await expect(page.getByRole('button', { name: 'Flagged (soft rules)' })).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByLabel('Status', { exact: true })).toHaveCount(0);
        await expect(page.getByRole('region', { name: 'Rules', exact: true }).getByRole('button')).toHaveCount(4);
        expect(errors).toEqual([]);
    });

    // Once per project: desktop at 1280px, phone at 360px.
    test('no serious or critical axe violations, and no page scroll sideways', async ({ page }) => {
        await page.goto('/admin/data-issues');
        await ready(page);
        await checkA11y(page);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    });
});

test.describe('Data issues, resolving the demo records with the keyboard', () => {
    test.skip(issues === null, 'Run with make e2e-issues');
    // The three records can be resolved once: the desktop project does it.
    test.beforeEach(({ page }) => {
        void page;
        test.skip(test.info().project.name !== 'desktop', 'Resolved once, on desktop');
    });
    test.describe.configure({ mode: 'serial' });

    test('correct the null-LGA record: accepted, gone from open, and on the dashboard', async ({ page }) => {
        test.setTimeout(120_000);
        const held = demo().null_lga;
        await page.goto('/admin/data-issues?rule=LGA_UNKNOWN');
        await ready(page);
        const record = row(page, held.instance_id);
        await expect(record).toBeVisible();

        await tabFromTop(page, record.getByRole('button', { name: 'Correct & reprocess' }));
        await page.keyboard.press('Enter');
        const form = page.getByRole('form', { name: `Correct ${held.instance_id}` });
        await expect(form).toBeVisible();
        await expect(form.getByText('As sent: (empty)').first()).toBeVisible();
        await expect(form.getByRole('textbox', { name: /score/i })).toHaveCount(0);

        await tabTo(page, form.getByLabel('LGA', { exact: true }));
        await page.keyboard.type(held.lga_name);
        await expect(form.getByLabel('LGA', { exact: true })).toHaveValue(held.lga_code);
        await tabTo(page, form.getByRole('button', { name: 'Review and confirm' }));
        await page.keyboard.press('Enter');

        const dialog = page.getByRole('dialog', { name: 'Correct and reprocess this submission?' });
        await expect(dialog.getByLabel('Reason')).toBeFocused();
        await page.keyboard.type('Assessor confirmed the LGA by phone');
        await tabTo(page, dialog.getByRole('button', { name: 'Correct and reprocess' }));
        await page.keyboard.press('Enter');

        await expect(page.getByRole('status').filter({ hasText: /Accepted/ })).toBeVisible({ timeout: 30_000 });
        await expect(page.getByRole('status').filter({ hasText: /A rescore of Q\d \d{4} is queued/ })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Follow it on the Rounds page' })).toBeVisible();
        await expect(record).toHaveCount(0);

        await page.goto('/admin/data-issues?state=resolved&rule=LGA_UNKNOWN');
        await ready(page);
        await expect(row(page, held.instance_id)).toContainText('Reprocessed');

        // On the dashboard: the facility now has its assessment in the round.
        const round = await page.request.get('/api/rounds').then(async (r) => ((await r.json()) as { data: { id: number; year: number; quarter: number }[] }).data.find((x) => x.id === demo().round_id));
        if (round === undefined) {
            throw new Error('The demo round is not in /api/rounds.');
        }
        await page.goto(`/dashboard/facility?year=${round.year}&quarter=${round.quarter}&facility=${held.facility_id}`);
        const drawer = page.getByRole('dialog');
        await expect(drawer.getByText(/by Demo assessor 1/)).toBeVisible({ timeout: 60_000 });
    });

    test('reject with a short reason is refused: the button stays disabled until it is long enough', async ({ page }) => {
        const [held] = wrongQuarter();
        await page.goto('/admin/data-issues?rule=ROUND_WINDOW');
        await ready(page);

        await tabFromTop(page, row(page, held.instance_id).getByRole('button', { name: 'Reject' }));
        await page.keyboard.press('Enter');
        const dialog = page.getByRole('dialog', { name: 'Reject this submission?' });
        await expect(dialog.getByLabel('Reason')).toBeFocused();
        await page.keyboard.type('short');
        await expect(dialog.getByRole('button', { name: 'Reject' })).toBeDisabled();
        await expect(dialog).toContainText('more characters needed');

        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(row(page, held.instance_id).getByRole('button', { name: 'Reject' })).toBeFocused();
        await expect(row(page, held.instance_id)).toBeVisible();
    });

    test('bulk-reject the two ROUND_WINDOW records', async ({ page }) => {
        const [one, two] = wrongQuarter();
        await page.goto('/admin/data-issues?rule=ROUND_WINDOW');
        await ready(page);

        await tabFromTop(page, page.getByRole('checkbox', { name: `Select ${one.instance_id}` }));
        await page.keyboard.press('Space');
        await tabTo(page, page.getByRole('checkbox', { name: `Select ${two.instance_id}` }));
        await page.keyboard.press('Space');

        const bar = page.getByRole('group', { name: 'Bulk actions' });
        await expect(bar).toContainText('2 selected');
        const reject = bar.getByRole('button', { name: 'Reject selected' });
        await expect(reject).toBeEnabled();
        await tabFromTop(page, reject);
        await page.keyboard.press('Enter');

        const dialog = page.getByRole('dialog', { name: 'Reject 2 submissions?' });
        await expect(dialog.getByLabel('Reason')).toBeFocused();
        await page.keyboard.type('Test submissions from the training day');
        await tabTo(page, dialog.getByRole('button', { name: 'Reject' }));
        await page.keyboard.press('Enter');

        await expect(page.getByRole('status').filter({ hasText: '2 records resolved.' })).toBeVisible({ timeout: 30_000 });
        await expect(row(page, one.instance_id)).toHaveCount(0);
        await expect(row(page, two.instance_id)).toHaveCount(0);

        await page.goto('/admin/data-issues?state=rejected&rule=ROUND_WINDOW');
        await ready(page);
        await expect(row(page, one.instance_id)).toBeVisible();
        await expect(row(page, two.instance_id)).toBeVisible();
        await checkA11y(page);
    });
});
