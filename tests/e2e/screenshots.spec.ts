import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';

// Not an assertion of pixels: saves Home and the prototype's Home at both viewports, then one
// side-by-side image per viewport, to storage/e2e/screenshots (`make e2e c="screenshots"`).

const DIR = 'storage/e2e/screenshots';

test.describe.configure({ mode: 'serial' });

test('saves Home for review', async ({ page }, testInfo) => {
    await page.goto('/dashboard/home?year=2026&quarter=2');
    await expect(page.locator('[aria-busy="true"]')).toHaveCount(0, { timeout: 60_000 }); // deferred data and the lazy chart, on a busy machine
    await expect(page.getByRole('img', { name: /Line chart/ })).toBeVisible();
    await page.screenshot({ path: `${DIR}/home-${testInfo.project.name}.png`, fullPage: true });
});

test("saves the prototype's Home for comparison", async ({ page }, testInfo) => {
    await page.goto(`file://${process.cwd()}/docs/${encodeURIComponent('kaduna-edqa-prototype-v4 (1).html')}`);
    await page.selectOption('#f-year', '2026');
    await page.selectOption('#f-q', 'Q2');
    await page.screenshot({ path: `${DIR}/prototype-home-${testInfo.project.name}.png`, fullPage: true });
});

test('puts the two side by side', async ({ page }, testInfo) => {
    const name = testInfo.project.name;
    const image = (file: string): string => `data:image/png;base64,${readFileSync(`${DIR}/${file}`).toString('base64')}`;
    const width = name === 'phone' ? 380 : 1290;

    await page.setViewportSize({ width: width * 2 + 48, height: 800 });
    await page.setContent(`
        <body style="margin:0;padding:16px;background:#ddd;font:600 14px sans-serif;display:flex;gap:16px;align-items:flex-start">
            <figure style="margin:0"><figcaption style="padding:0 0 6px">Built: /dashboard/home (2026 Q2)</figcaption><img src="${image(`home-${name}.png`)}" style="width:${width}px;display:block"></figure>
            <figure style="margin:0"><figcaption style="padding:0 0 6px">Prototype v4: Home (2026 Q2)</figcaption><img src="${image(`prototype-home-${name}.png`)}" style="width:${width}px;display:block"></figure>
        </body>`);
    await page.screenshot({ path: `${DIR}/compare-home-${name}.png`, fullPage: true });
});
