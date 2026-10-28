import { expect, test, type Locator, type Page } from '@playwright/test';
import { checkA11y } from './support/a11y';

// Correcting an assessment from the facility drawer (ARCHITECTURE.md §9.2.7, D-58), keyboard
// only. Q2 2026 is open in the seeded data. The first item's result is flipped each run, so the
// journey can be repeated; the desktop project does it once.

const PAGE = '/dashboard/facility?year=2026&quarter=2';

/** Press Tab from where focus is now until the target has it: no mouse. */
async function tabTo(page: Page, target: Locator, max = 200): Promise<void> {
    for (let i = 0; i < max; i += 1) {
        if (await target.evaluate((el) => el === document.activeElement).catch(() => false)) {
            return;
        }
        await page.keyboard.press('Tab');
    }
    throw new Error('The target was never reached with Tab.');
}

async function openFirstFacility(page: Page): Promise<Locator> {
    await page.goto(PAGE);
    await expect(page.getByRole('heading', { level: 1, name: 'Facility view' })).toBeVisible({ timeout: 60_000 });
    await expect(page.locator('[aria-busy="true"]')).toHaveCount(0, { timeout: 60_000 });
    const trigger = page.getByRole('table', { name: /Facilities in view/ }).getByRole('rowheader').first().getByRole('button');
    const name = ((await trigger.textContent()) ?? '').trim();

    await trigger.focus();
    await page.keyboard.press('Enter');
    const drawer = page.getByRole('dialog', { name });
    await expect(drawer.getByRole('heading', { level: 3, name: 'Scores, Q2 2026' })).toBeVisible({ timeout: 60_000 });

    return drawer;
}

test.describe('Correcting an assessment from the drawer', () => {
    test.describe.configure({ mode: 'serial' });
    test.beforeEach(({ page }) => {
        void page;
        test.skip(test.info().project.name !== 'desktop', 'Corrected once, on desktop');
    });

    test('keyboard only: change an item, read the summary, give the reason, and see it in the audit trail', async ({ page }) => {
        test.setTimeout(240_000);
        const drawer = await openFirstFacility(page);

        await tabTo(page, drawer.getByRole('button', { name: 'Correct this assessment' }));
        await page.keyboard.press('Enter');
        const panel = drawer.getByRole('region', { name: 'Correcting this assessment' });
        await expect(panel).toBeVisible();
        await expect(panel.getByRole('status')).toHaveText('No changes yet.');
        await expect(panel.getByRole('button', { name: 'Review and save' })).toBeDisabled();
        await checkA11y(page);

        const select = drawer.getByRole('combobox').first();
        const before = await select.inputValue();
        const after = before === 'pass' ? 'fail' : 'pass';
        await tabTo(page, select);
        await page.keyboard.type(after === 'pass' ? 'P' : 'F'); // typeahead on the native select
        await expect(select).toHaveValue(after);
        const summary = panel.getByRole('status');
        await expect(summary).toContainText('1 item changed:');
        await expect(summary).toContainText(before === 'pass' ? 'Pass → Fail' : 'Fail → Pass');
        const key = ((await summary.textContent()) ?? '').match(/(avail|consist|valid)_m\d_[a-z0-9_]+/)?.[0] ?? '';
        expect(key).not.toBe('');

        // Esc in the reason dialog closes the dialog only, not the drawer.
        await tabTo(page, panel.getByRole('button', { name: 'Review and save' }));
        await page.keyboard.press('Enter');
        let dialog = page.getByRole('dialog', { name: 'Save this correction?' });
        await expect(dialog.getByLabel('Reason')).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(drawer).toBeVisible();
        await expect(panel.getByRole('button', { name: 'Review and save' })).toBeFocused();

        await page.keyboard.press('Enter');
        dialog = page.getByRole('dialog', { name: 'Save this correction?' });
        await expect(dialog).toContainText('1 item changed:');
        await expect(dialog.getByLabel('Reason')).toBeFocused();
        await page.keyboard.type('short');
        await expect(dialog.getByRole('button', { name: 'Save correction' })).toBeDisabled();
        await page.keyboard.type(' — supervisor re-checked the register');
        await tabTo(page, dialog.getByRole('button', { name: 'Save correction' }));
        await page.keyboard.press('Enter');

        // The drawer reloads: out of correction mode, the item shows its new result, and the
        // correction is in the audit trail with the reason.
        await expect(dialog).toBeHidden({ timeout: 60_000 });
        await expect(drawer.getByRole('button', { name: 'Correct this assessment' })).toBeVisible({ timeout: 60_000 });
        await expect(drawer.getByRole('combobox')).toHaveCount(0);
        const changes = drawer.getByRole('heading', { level: 3, name: 'Changes' }).locator('xpath=..');
        const entry = changes.getByRole('listitem').filter({ hasText: 'Corrected' }).filter({ hasText: 'supervisor re-checked the register' }).first();
        await expect(entry).toBeVisible();
        // The old side is the stored value, which may be the form's raw choice (yes / no).
        await expect(entry).toContainText(`→ ${after}`);
        await checkA11y(page);

        await page.keyboard.press('Escape');
        await expect(drawer).toBeHidden();
    });
});
