import { expect, test, type Locator, type Page } from '@playwright/test';
import { checkA11y } from './support/a11y';

// The ODK pull page (ARCHITECTURE.md §9.3, D-56). Two set-ups:
// - `make e2e`: the dev stack has no ODK settings, so the page explains what is missing;
// - `make e2e-odk`: the app and worker point at the mock ODK Central (compose.e2e-odk.yml,
//   E2E_ODK=1), so Pull now runs end to end.
const againstMock = process.env.E2E_ODK === '1';

async function ready(page: Page): Promise<void> {
    await expect(page.getByRole('heading', { level: 1, name: 'ODK pull' })).toBeVisible({ timeout: 60_000 });
}

/** Tab from the top of the page until the target has focus: no mouse. */
async function tabTo(page: Page, target: Locator, max = 60): Promise<void> {
    await page.locator('body').focus();
    for (let i = 0; i < max; i += 1) {
        await page.keyboard.press('Tab');
        if (await target.evaluate((el) => el === document.activeElement)) {
            return;
        }
    }
    throw new Error('The target was never reached with Tab.');
}

test.describe('ODK pull page, ODK not configured', () => {
    test.skip(againstMock, 'make e2e-odk configures the mock');

    test('says what is missing and links the runbook, with no errors', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('console', (message) => {
            if (message.type() === 'error') {
                errors.push(message.text());
            }
        });
        await page.goto('/admin/odk');
        await ready(page);

        const connection = page.getByRole('region', { name: 'Connection to ODK Central' });
        await expect(connection).toContainText('Not configured');
        await expect(connection.getByText('ODK_CENTRAL_URL')).toBeVisible();
        await expect(connection.getByRole('link', { name: /credentials-day runbook/ })).toHaveAttribute('href', /odk-credentials-day\.md$/);
        await expect(page.getByRole('button', { name: 'Pull now' })).toHaveCount(0);
        await expect(page.getByRole('region', { name: 'Pull history' })).toBeVisible();
        await expect(page.getByRole('region', { name: 'Historical backfill' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Regenerate ODK facility file' })).toBeVisible();
        expect(errors).toEqual([]);
    });

    test('no serious or critical axe violations', async ({ page }) => {
        await page.goto('/admin/odk');
        await ready(page);
        await checkA11y(page);
    });
});

test.describe('ODK pull page, against the mock', () => {
    test.skip(!againstMock, 'Run with make e2e-odk');
    test.describe.configure({ mode: 'serial' }); // one pull at a time on the one stack

    test('Pull now, keyboard only: progress to the end, then the history row', async ({ page }) => {
        test.setTimeout(240_000);
        await page.goto('/admin/odk');
        await ready(page);
        await expect(page.getByRole('region', { name: 'Connection to ODK Central' })).toContainText('Connected');

        const button = page.getByRole('button', { name: 'Pull now' });
        await tabTo(page, button);
        await page.keyboard.press('Enter');

        const progress = page.getByRole('region', { name: 'Pull', exact: true });
        await expect(progress).toBeVisible();
        await expect(button).toBeDisabled();
        await expect(progress.getByText('Finished')).toBeVisible({ timeout: 180_000 });
        await expect(progress.getByRole('term').filter({ hasText: 'Fetched' })).toBeVisible();
        await expect(button).toBeEnabled();

        const history = page.getByRole('region', { name: 'Pull history' });
        const first = history.getByRole('row').nth(1);
        await expect(first).toContainText('Pull now');
        await expect(first).toContainText('Succeeded');
        await expect(first).toContainText('E2E Administrator');
    });

    test('the history filters reach the row by keyboard', async ({ page }) => {
        await page.goto('/admin/odk');
        await ready(page);
        const trigger = page.getByLabel('Trigger');
        await tabTo(page, trigger);
        await page.keyboard.press('ArrowDown'); // native select: the next option is chosen at once
        await expect(page).toHaveURL(/trigger=/, { timeout: 30_000 });
        await expect(page.getByRole('region', { name: 'Pull history' }).getByRole('row').first()).toBeVisible();
    });

    test('no serious or critical axe violations, with a finished pull on the page', async ({ page }) => {
        await page.goto('/admin/odk');
        await ready(page);
        await checkA11y(page);
    });
});
