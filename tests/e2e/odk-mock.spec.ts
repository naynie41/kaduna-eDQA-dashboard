import { expect, test, type Page } from '@playwright/test';

// Phase 2B close-out: after a pull from the mock ODK Central, the dashboard shows the new visits.
// The expected figures are read from the database after the pull and passed in, so this runs only
// when they are given (docs/phase-2b-closeout.md):
//   E2E_ODK_VISITED       facilities visited in Q2 2026 (accepted and flagged assessments)
//   E2E_ODK_LGA           an LGA with new visits, and
//   E2E_ODK_LGA_COUNT     its facilities visited in Q2 2026
//   E2E_ODK_QUARANTINED   the instance IDs the pull quarantined, comma-separated, as
//                         "uuid:...=CODE+CODE" with each record's failure codes
const visited = process.env.E2E_ODK_VISITED;
const lga = process.env.E2E_ODK_LGA;
const lgaCount = process.env.E2E_ODK_LGA_COUNT;
const quarantined = process.env.E2E_ODK_QUARANTINED;

const formatted = (value: string) => Number(value).toLocaleString('en-GB');
const escape = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

async function ready(page: Page, heading: string): Promise<void> {
    await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible();
    await expect(page.locator('[aria-busy="true"]')).toHaveCount(0, { timeout: 60_000 });
}

test.describe('Data pulled from the mock ODK Central', () => {
    test.skip(visited === undefined || lga === undefined || lgaCount === undefined, 'Run by the Phase 2B close-out with the expected figures');

    test('Home counts the pulled visits', async ({ page }) => {
        await page.goto('/dashboard/home?year=2026&quarter=2');
        await ready(page, 'Home');

        const term = page.getByRole('term').filter({ hasText: 'Facilities visited' });
        await expect(term.locator('xpath=following-sibling::*[1]')).toHaveText(new RegExp(`^${escape(formatted(String(visited)))}`));
    });

    test('the LGA view counts them, in total and for an LGA with new visits', async ({ page }) => {
        await page.goto('/dashboard/lga?year=2026&quarter=2');
        await ready(page, 'LGA view');

        await expect(page.getByText(`${formatted(String(visited))} facilities in view`).first()).toBeVisible();
        await expect(page.getByRole('button', { name: new RegExp(`^${escape(String(lga))}, .*, ${escape(formatted(String(lgaCount)))} facilities$`) })).toBeVisible();
    });

    test('the broken ones are in quarantine, through GET /api/quarantine, each with its rules', async ({ page }) => {
        test.skip(quarantined === undefined, 'No quarantined instance IDs given');
        const expected = new Map<string, string[]>(String(quarantined).split(',').map((pair) => {
            const [id = '', codes = ''] = pair.split('=');
            return [id, codes.split('+')];
        }));

        // The signed-in session, as the Data issues page would call it; newest first, so page 1 has them.
        const response = await page.request.get('/api/quarantine?status=open', { headers: { Accept: 'application/json' } });
        expect(response.status()).toBe(200);
        const body = (await response.json()) as { data: { instance_id: string; failures: { code: string }[] }[] };
        const found = new Map(body.data.map((record) => [record.instance_id, record.failures.map((f) => f.code)]));

        for (const [id, codes] of expected) {
            expect(found.get(id), id).toEqual(codes);
        }
    });
});
