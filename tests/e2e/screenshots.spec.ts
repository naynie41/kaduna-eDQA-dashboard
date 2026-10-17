import { readFileSync } from 'node:fs';
import { expect, test, type Page } from '@playwright/test';

// Not an assertion of pixels: saves each built page and the prototype's version at both viewports,
// then one side-by-side image per page and viewport, to storage/e2e/screenshots
// (`make e2e c="screenshots"`).

const DIR = 'storage/e2e/screenshots';
const PROTOTYPE = `file://${process.cwd()}/docs/${encodeURIComponent('kaduna-edqa-prototype-v4 (1).html')}`;

/** Each page: its path, how to tell it has loaded, and the prototype tab that matches it. */
const PAGES = [
    { name: 'home', path: '/dashboard/home?year=2026&quarter=2', loaded: /Line chart/, prototypeTab: 'Home' },
    { name: 'lga', path: '/dashboard/lga?year=2026&quarter=2', loaded: null, prototypeTab: 'LGA view' },
    { name: 'facility', path: '/dashboard/facility?year=2026&quarter=2', loaded: null, prototypeTab: 'Facility view' },
    { name: 'monitor', path: '/dashboard/monitor?year=2026&quarter=2', loaded: null, prototypeTab: 'Monitor' },
    { name: 'visuals', path: '/dashboard/visuals?year=2026&quarter=2', loaded: /April 2026: grouped bars/, prototypeTab: 'Visuals' },
    { name: 'plan', path: '/dashboard/plan?year=2026&quarter=2', loaded: null, prototypeTab: 'Implementation plan' },
] as const;

test.describe.configure({ mode: 'serial' });

for (const target of PAGES) {
    test(`saves ${target.name} for review`, async ({ page }, testInfo) => {
        await page.goto(target.path);
        await expect(page.locator('[aria-busy="true"]')).toHaveCount(0, { timeout: 60_000 }); // deferred data and the lazy chart, on a busy machine
        if (target.loaded !== null) {
            await expect(page.getByRole('img', { name: target.loaded })).toBeVisible();
        }
        await page.screenshot({ path: `${DIR}/${target.name}-${testInfo.project.name}.png`, fullPage: true });
    });

    test(`saves the prototype's ${target.name} for comparison`, async ({ page }, testInfo) => {
        await page.goto(PROTOTYPE);
        await page.selectOption('#f-year', '2026');
        await page.selectOption('#f-q', 'Q2');
        await page.locator('#tabs').getByRole('tab', { name: new RegExp(`^${target.prototypeTab}`) }).click();
        await page.screenshot({ path: `${DIR}/prototype-${target.name}-${testInfo.project.name}.png`, fullPage: true });
    });

    test(`puts ${target.name} side by side`, async ({ page }, testInfo) => {
        await sideBySide(page, target.name, testInfo.project.name);
    });
}

async function sideBySide(page: Page, name: string, project: string): Promise<void> {
    const image = (file: string): string => `data:image/png;base64,${readFileSync(`${DIR}/${file}`).toString('base64')}`;
    const width = project === 'phone' ? 380 : 1290;

    await page.setViewportSize({ width: width * 2 + 48, height: 800 });
    await page.setContent(`
        <body style="margin:0;padding:16px;background:#ddd;font:600 14px sans-serif;display:flex;gap:16px;align-items:flex-start">
            <figure style="margin:0"><figcaption style="padding:0 0 6px">Built: ${name} (2026 Q2)</figcaption><img src="${image(`${name}-${project}.png`)}" style="width:${width}px;display:block"></figure>
            <figure style="margin:0"><figcaption style="padding:0 0 6px">Prototype v4: ${name} (2026 Q2)</figcaption><img src="${image(`prototype-${name}-${project}.png`)}" style="width:${width}px;display:block"></figure>
        </body>`);
    await page.screenshot({ path: `${DIR}/compare-${name}-${project}.png`, fullPage: true });
}
