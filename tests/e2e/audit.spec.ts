import { mkdirSync, writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { AUTH_STATE } from '../../playwright.config';
import { E2E_PASSWORD } from './support/credentials';

// The Phase 4 accessibility audit (docs/accessibility-audit-phase4.md): axe on every page and the
// drawer at 360 and 1280 (any WCAG 2.2 A/AA violation fails, whatever its impact), forced-colours
// and 200% zoom screenshots, reduced motion, and keyboard-only journeys. Results land in
// storage/e2e/a11y and storage/e2e/screenshots. `make e2e c="audit"`.

const OUT = 'storage/e2e/a11y';
const SHOTS = 'storage/e2e/screenshots';
const Q = 'year=2026&quarter=2';

/** Every signed-in page, and how to tell it has loaded. */
const PAGES: { name: string; path: string; heading: string }[] = [
    { name: 'home', path: `/dashboard/home?${Q}`, heading: 'Home' },
    { name: 'facility', path: `/dashboard/facility?${Q}`, heading: 'Facility view' },
    { name: 'lga', path: `/dashboard/lga?${Q}`, heading: 'LGA view' },
    { name: 'monitor', path: `/dashboard/monitor?${Q}`, heading: 'Monitor' },
    { name: 'visuals', path: `/dashboard/visuals?${Q}`, heading: 'Visuals' },
    { name: 'plan', path: `/dashboard/plan?${Q}`, heading: 'Implementation plan' },
    { name: 'admin-odk', path: '/admin', heading: 'ODK pull' }, // /admin opens the ODK pull page
    { name: 'admin-data-issues', path: '/admin/data-issues', heading: 'Data issues' },
    { name: 'admin-rounds', path: '/admin/rounds', heading: 'Rounds' },
    { name: 'admin-facilities', path: '/admin/facilities', heading: 'Facility list' },
    { name: 'admin-scoring-rules', path: '/admin/scoring-rules', heading: 'Scoring rules' },
    { name: 'security', path: '/settings/security', heading: 'Security' },
];

async function ready(page: Page, heading: string): Promise<void> {
    await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible({ timeout: 60_000 });
    await expect(page.locator('[aria-busy="true"]')).toHaveCount(0, { timeout: 60_000 }); // deferred data and lazy charts
}

/**
 * Opens a page as a person would: Security first asks for the password again (password.confirm),
 * and that page is audited too when a project is given.
 */
async function open(page: Page, target: { name: string; path: string; heading: string }, project?: string): Promise<void> {
    await page.goto(target.path);
    const confirm = page.getByRole('heading', { level: 1, name: 'Confirm your password' });
    // Wait for whichever page arrives (isVisible() does not wait).
    await expect(page.getByRole('heading', { level: 1, name: target.heading }).or(confirm)).toBeVisible({ timeout: 60_000 });
    if (await confirm.isVisible()) {
        if (project !== undefined) {
            await audit(page, 'confirm-password', project);
        }
        // The two projects share one server session (the saved sign-in) and may confirm at the
        // same moment: one session write can drop the other's confirmation, or take its
        // "intended" URL. So confirm until it holds, then open the page again.
        for (let attempt = 0; attempt < 3 && (await confirm.isVisible()); attempt++) {
            await page.getByLabel('Password').fill(E2E_PASSWORD);
            await page.getByRole('button', { name: 'Continue' }).click();
            await expect(confirm).toBeHidden({ timeout: 20_000 }).catch(() => undefined);
            await page.goto(target.path);
            await expect(page.getByRole('heading', { level: 1, name: target.heading }).or(confirm)).toBeVisible({ timeout: 60_000 });
        }
    }
    await ready(page, target.heading);
}

/** axe against WCAG 2.2 A and AA: every violation fails, and the full result is kept. */
async function audit(page: Page, name: string, project: string): Promise<void> {
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    mkdirSync(OUT, { recursive: true });
    // Incomplete: what axe could not decide alone (for the manual review), with its reason.
    const incomplete = results.incomplete.map((r) => ({
        id: r.id,
        help: r.help,
        nodes: r.nodes.length,
        reasons: [...new Set(r.nodes.map((node) => String((node.any[0]?.data as { messageKey?: string } | undefined)?.messageKey ?? node.any[0]?.message ?? 'unknown')))],
    }));
    writeFileSync(`${OUT}/${name}-${project}.json`, JSON.stringify({ violations: results.violations, incomplete }, null, 2));

    const report = results.violations.map((v) => `${v.impact} ${v.id}: ${v.help}\n  ${v.nodes.map((node) => node.target.join(' ')).join('\n  ')}`).join('\n');
    expect(results.violations, report).toEqual([]);
}

/** Tab (forwards) until the target has focus: proves it is in the tab order, not only focusable. */
async function tabTo(page: Page, target: Locator, max = 250): Promise<void> {
    for (let i = 0; i < max; i++) {
        if (await target.evaluate((el) => el === document.activeElement).catch(() => false)) {
            return;
        }
        await page.keyboard.press('Tab');
    }
    throw new Error(`Not reached by Tab in ${max} presses: ${target.toString()}`);
}

/**
 * In forced colours a state shown only by a fill disappears. Every pressed or current control must
 * still look different from its unselected siblings (background, text or bottom border).
 */
async function selectedStandsOut(page: Page): Promise<void> {
    const same = await page.evaluate(() => {
        const look = (el: Element) => {
            const s = getComputedStyle(el);
            return `${s.backgroundColor}|${s.color}|${s.borderBottomColor}`;
        };
        const problems: string[] = [];
        for (const selected of document.querySelectorAll('[aria-pressed="true"], [aria-current="page"]')) {
            const siblings = [...(selected.closest('ul, [role="group"], nav')?.querySelectorAll('a, button') ?? [])].filter((el) => el !== selected && !el.matches('[aria-pressed="true"], [aria-current="page"]'));
            if (siblings.length > 0 && siblings.every((el) => look(el) === look(selected))) {
                problems.push((selected.textContent ?? '').trim());
            }
        }
        return problems;
    });
    expect(same, `selected but indistinguishable in forced colours: ${same.join(', ')}`).toEqual([]);
}

async function noSidewaysScroll(page: Page): Promise<void> {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
}

test.describe('axe on every page', () => {
    for (const target of PAGES) {
        test(`${target.name}`, async ({ page }, testInfo) => {
            await open(page, target, testInfo.project.name);
            await audit(page, target.name, testInfo.project.name);
        });
    }

    test('the facility drawer', async ({ page }, testInfo) => {
        await page.goto(`/dashboard/facility?${Q}`);
        await ready(page, 'Facility view');
        await page.getByRole('table', { name: /Facilities in view/ }).getByRole('rowheader').first().getByRole('button').click();
        await expect(page.getByRole('dialog').getByRole('heading', { level: 3 }).first()).toBeVisible({ timeout: 60_000 });
        await expect(page.getByRole('dialog').locator('[aria-busy="true"]')).toHaveCount(0, { timeout: 60_000 });
        await audit(page, 'drawer', testInfo.project.name);
    });
});

test.describe('signed out', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('the sign-in page', async ({ page }, testInfo) => {
        await page.goto('/login');
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible({ timeout: 60_000 });
        await audit(page, 'login', testInfo.project.name);
    });
});

test.describe('forced colours and 200% zoom', () => {
    test.skip(({ viewport }) => (viewport?.width ?? 0) < 1000, 'the 1280 project: zoom emulates 200% of it');

    for (const target of PAGES) {
        test(`${target.name}`, async ({ page, browser }) => {
            mkdirSync(SHOTS, { recursive: true });

            await page.emulateMedia({ forcedColors: 'active' });
            await open(page, target);
            await selectedStandsOut(page);
            await page.screenshot({ path: `${SHOTS}/forced-${target.name}.png`, fullPage: true });

            // 200% zoom of a 1280 × 800 window: a 640 × 400 CSS viewport at device scale 2.
            const zoomed = await browser.newContext({ storageState: AUTH_STATE, viewport: { width: 640, height: 400 }, deviceScaleFactor: 2 });
            const zoomPage = await zoomed.newPage();
            await open(zoomPage, target);
            await noSidewaysScroll(zoomPage);
            await zoomPage.screenshot({ path: `${SHOTS}/zoom200-${target.name}.png`, fullPage: true });
            await zoomed.close();
        });
    }
});

test.describe('reduced motion', () => {
    test('loading placeholders stop pulsing and nothing keeps animating', async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.goto('/dev/components');
        const skeleton = page.locator('[aria-busy="true"]').first();
        await expect(skeleton).toBeVisible({ timeout: 60_000 });
        expect(await skeleton.evaluate((el) => getComputedStyle(el).animationName)).toBe('none');

        for (const target of PAGES.slice(0, 6)) {
            await page.goto(target.path);
            await ready(page, target.heading);
            const running = await page.evaluate(() => document.getAnimations().filter((a) => a.playState === 'running').length);
            expect(running, `${target.name}: animations still running`).toBe(0);
        }

        await page.emulateMedia({ reducedMotion: 'no-preference' });
        await page.goto('/dev/components');
        expect(await page.locator('[aria-busy="true"]').first().evaluate((el) => getComputedStyle(el).animationName)).not.toBe('none');
    });
});

test.describe('charts load after numbers', () => {
    // On the page's own clock: when the first figure and the first chart are in the DOM, when the
    // deferred data arrived, and when the chart code (Recharts, the lazy chart components) started
    // downloading. Figures paint before any chart, and the chart code is fetched only after the
    // data. (On Visuals the figures come in that same deferred response, so React starts the lazy
    // import in the very render that paints them.)
    for (const [name, path, heading] of [
        ['home', `/dashboard/home?${Q}`, 'Home'],
        ['visuals', `/dashboard/visuals?${Q}`, 'Visuals'],
    ] as const) {
        test(name, async ({ page }) => {
            await page.addInitScript(() => {
                const w = window as unknown as { numbersAt: number | null; chartAt: number | null };
                w.numbersAt = null;
                w.chartAt = null;
                new MutationObserver(() => {
                    const figure = [...document.querySelectorAll('main dd, main .tabular-nums')].find((el) => /\d/.test(el.textContent ?? ''));
                    if (w.numbersAt === null && figure !== undefined) {
                        w.numbersAt = performance.now();
                    }
                    if (w.chartAt === null && document.querySelector('main [role="img"] svg') !== null) {
                        w.chartAt = performance.now();
                    }
                }).observe(document, { childList: true, subtree: true, characterData: true });
            });
            await page.goto(path);
            await ready(page, heading);
            await expect(page.getByRole('img', { name: /Line chart|grouped bars/ }).first()).toBeVisible({ timeout: 60_000 });

            const timing = await page.evaluate(() => {
                const w = window as unknown as { numbersAt: number | null; chartAt: number | null };
                const resources = performance.getEntriesByType('resource') as PerformanceResourceTiming[];

                return {
                    numbersAt: w.numbersAt,
                    chartAt: w.chartAt,
                    dataArrived: Math.min(...resources.filter((r) => r.initiatorType === 'xmlhttprequest' || r.initiatorType === 'fetch').map((r) => r.responseEnd)),
                    chartCode: Math.min(...resources.filter((r) => /TrendChart|MonthGroupChart|recharts|CartesianChart/.test(r.name)).map((r) => r.startTime)),
                };
            });
            expect(timing.numbersAt).not.toBeNull();
            expect(timing.chartAt).not.toBeNull();
            expect(timing.numbersAt ?? Infinity, 'figures paint before any chart').toBeLessThan(timing.chartAt ?? 0);
            expect(timing.chartCode, 'the chart code is fetched only after the data arrives').toBeGreaterThan(timing.dataArrived);
        });
    }
});

test.describe('keyboard only', () => {
    test('filter → LGA tile → facility → drawer → close', async ({ page }) => {
        await page.goto(`/dashboard/home?${Q}`);
        await ready(page, 'Home');

        // Filter: the LGA select, changed with the arrow keys.
        const lgaSelect = page.getByRole('combobox', { name: 'LGA' });
        await tabTo(page, lgaSelect);
        await page.keyboard.press('ArrowDown');
        await expect(page).toHaveURL(/[?&]lga=[A-Z]+/, { timeout: 60_000 });
        await expect(lgaSelect).toBeFocused();

        // To the LGA view by its tab, then choose another tile.
        await tabTo(page, page.getByRole('link', { name: /^LGA view/ }));
        await page.keyboard.press('Enter');
        await ready(page, 'LGA view');
        const tile = page.getByRole('button', { name: /^Zaria/ });
        await tabTo(page, tile);
        await page.keyboard.press('Enter');
        await expect(tile).toHaveAttribute('aria-pressed', 'true', { timeout: 60_000 });
        await expect(page).toHaveURL(/[?&]lga=/);
        await expect(tile).toBeFocused();

        // To the Facility view, open the first facility, close with Esc.
        await tabTo(page, page.getByRole('link', { name: 'Facility view' }));
        await page.keyboard.press('Enter');
        await ready(page, 'Facility view');
        const facility = page.getByRole('table', { name: /Facilities in view/ }).getByRole('rowheader').first().getByRole('button');
        await tabTo(page, facility);
        const name = ((await facility.textContent()) ?? '').trim();
        await page.keyboard.press('Enter');
        const dialog = page.getByRole('dialog', { name });
        await expect(dialog.getByRole('heading', { level: 2, name })).toBeFocused({ timeout: 60_000 });
        await page.keyboard.press('Tab');
        await expect(dialog.getByRole('button', { name: 'Close' })).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(facility).toBeFocused();
    });

    test('sort and paginate the facility table', async ({ page }) => {
        await page.goto(`/dashboard/facility?${Q}`);
        await ready(page, 'Facility view');

        const ward = page.getByRole('table', { name: /Facilities in view/ }).getByRole('button', { name: 'Ward' });
        await tabTo(page, ward);
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(/[?&]sort=ward(&|$)/, { timeout: 60_000 });
        await expect(page.getByRole('table', { name: /by Ward, A to Z/ }).getByRole('button', { name: 'Ward' })).toBeFocused();
        await page.keyboard.press('Enter'); // again: Z to A
        await expect(page.getByRole('table', { name: /by Ward, Z to A/ })).toBeVisible({ timeout: 60_000 });

        const next = page.getByRole('navigation', { name: 'Facility pages' }).getByRole('button', { name: 'Next' });
        await tabTo(page, next);
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(/[?&]page=2(&|$)/, { timeout: 60_000 });
        await expect(page.getByText(/^Showing 51–100 of [\d,]+ facilities$/)).toBeVisible();
        await expect(page.locator('[aria-live="polite"][aria-atomic="true"]')).toContainText(/51 to 100|Page 2/);
    });
});
