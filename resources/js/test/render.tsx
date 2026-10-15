import type { ReactElement } from 'react';
import { render, type RenderResult } from '@testing-library/react';
import { axe } from 'vitest-axe';
import Announcer from '@/components/Announcer';
import { I18nProvider, type Translations } from '@/lib/i18n';

/**
 * The strings components read in tests: the same keys as lang/en/common.php, kept short so a
 * test can find elements by their visible text.
 */
export const testTranslations: Translations = {
    common: {
        no_data: 'No data',
        loading: 'Loading',
        months: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        months_short: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        month_label: ':month :year',
        month_slot: 'Month :slot',
        ui: {
            show_table: 'Show as table',
            show_chart: 'Show as chart',
            scroll_region: ':label, scrollable',
        },
    },
};

/** Renders inside the providers every page has: translations and the announcer. */
export function renderUi(ui: ReactElement, translations: Translations = testTranslations): RenderResult {
    return render(
        <I18nProvider translations={translations}>
            <Announcer>{ui}</Announcer>
        </I18nProvider>,
    );
}

/** axe over the rendered container; region rule off, as a component is not a whole page. */
export async function axeCheck(container: Element) {
    return axe(container, { rules: { region: { enabled: false } } });
}
