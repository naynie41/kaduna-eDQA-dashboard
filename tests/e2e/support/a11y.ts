import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';

/**
 * axe over the whole page against WCAG 2.2 AA; fails on serious or critical violations, listing
 * each rule, its help text and the elements, so a failure says what to fix.
 */
export async function checkA11y(page: Page): Promise<void> {
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    const blocking = results.violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');

    const report = blocking.map((v) => `${v.impact} ${v.id}: ${v.help}\n  ${v.nodes.map((node) => node.target.join(' ')).join('\n  ')}`).join('\n');
    expect(blocking, report).toEqual([]);
}
