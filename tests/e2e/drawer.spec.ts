import { expect, test, type Locator, type Page } from '@playwright/test';
import { checkA11y } from './support/a11y';

const PAGE = '/dashboard/facility?year=2026&quarter=2';

async function firstFacility(page: Page): Promise<Locator> {
    await page.goto(PAGE);
    await expect(page.getByRole('heading', { level: 1, name: 'Facility view' })).toBeVisible();
    await expect(page.locator('[aria-busy="true"]')).toHaveCount(0, { timeout: 60_000 }); // deferred table

    return page.getByRole('table', { name: /Facilities in view/ }).getByRole('rowheader').first().getByRole('button');
}

async function loaded(dialog: Locator): Promise<void> {
    await expect(dialog.getByRole('heading', { level: 3, name: 'Scores, Q2 2026' })).toBeVisible({ timeout: 60_000 });
}

test.describe('Facility detail drawer', () => {
    test('opens from the table by keyboard, keeps focus inside, and Esc returns focus to the name', async ({ page }) => {
        const trigger = await firstFacility(page);
        const name = ((await trigger.textContent()) ?? '').trim();

        await trigger.focus();
        await page.keyboard.press('Enter');

        const dialog = page.getByRole('dialog', { name });
        await expect(dialog).toHaveAttribute('aria-modal', 'true');
        await expect(dialog.getByRole('heading', { level: 2, name })).toBeFocused();
        await expect(page).toHaveURL(/[?&]facility=\d+(&|$)/);
        await loaded(dialog);

        // Round the whole dialog both ways: focus never leaves it.
        for (const key of [...Array<string>(25).fill('Tab'), ...Array<string>(10).fill('Shift+Tab')]) {
            await page.keyboard.press(key);
            expect(await dialog.evaluate((el) => el.contains(document.activeElement))).toBe(true);
        }

        await checkA11y(page);

        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(trigger).toBeFocused();
        await expect(page).not.toHaveURL(/facility=/);
    });

    test('a shared link opens it, named after the facility, and Close returns to the page', async ({ page }) => {
        const trigger = await firstFacility(page);
        const name = ((await trigger.textContent()) ?? '').trim();
        await trigger.click();
        await expect(page).toHaveURL(/[?&]facility=\d+/);
        const link = page.url();

        await page.goto(link);
        const dialog = page.getByRole('dialog', { name });
        await expect(dialog).toBeVisible({ timeout: 60_000 });
        await loaded(dialog);
        await expect(dialog.getByRole('table', { name: 'Overall by round' })).toBeVisible();
        await checkA11y(page);

        await dialog.getByRole('button', { name: 'Close' }).click();
        await expect(dialog).toBeHidden();
        await expect(page).not.toHaveURL(/facility=/);
    });

    test('saves the drawer for review', async ({ page }, testInfo) => {
        const trigger = await firstFacility(page);
        await trigger.click();
        const dialog = page.getByRole('dialog');
        await loaded(dialog);
        await page.screenshot({ path: `storage/e2e/screenshots/drawer-${testInfo.project.name}.png` });
        await dialog.locator('div.overflow-y-auto').evaluate((el) => el.scrollTo(0, el.scrollHeight));
        await page.screenshot({ path: `storage/e2e/screenshots/drawer-${testInfo.project.name}-end.png` });
    });
});
