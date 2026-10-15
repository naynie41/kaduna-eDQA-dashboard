import Tabs from '@/components/Tabs';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { DASHBOARD_PAGES, type DashboardPage } from '@/lib/pageMeta';
import type { Filters } from '@/types/shell';

export type DashboardTabsProps = {
    current: DashboardPage | null;
    filters: Filters;
    /** LGAs with an assessment in view: never more than 23. */
    lgaCount: number;
};

/** The six dashboard pages; each link keeps the filters, so a view moves between pages intact. */
export default function DashboardTabs({ current, filters, lgaCount }: DashboardTabsProps) {
    const t = useTranslate();
    const query = new URLSearchParams(
        Object.entries(filters).flatMap(([key, value]) => (value === null ? [] : [[key, String(value)]])),
    ).toString();

    return (
        <Tabs
            label={t('dashboard.tabs.label')}
            items={DASHBOARD_PAGES.map((page) => ({
                href: `/dashboard/${page}${query === '' ? '' : `?${query}`}`,
                label: t(`dashboard.pages.${page}.title`),
                current: page === current,
                ...(page === 'lga'
                    ? { badge: { text: formatCount(lgaCount), label: t('dashboard.tabs.lga_badge', { count: formatCount(lgaCount) }) } }
                    : {}),
            }))}
        />
    );
}
