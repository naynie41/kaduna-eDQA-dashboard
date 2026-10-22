import Tabs from '@/components/Tabs';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { ADMIN_PAGES, type AdminPage } from '@/lib/pageMeta';

export type AdminTabsProps = {
    current: AdminPage | null;
    /** Open quarantine records, from the server: the Data issues badge. */
    openQuarantineCount: number;
};

/**
 * The five administration pages (ARCHITECTURE.md §9.3). Data issues carries the open quarantine
 * count as a badge, in the alert style while there are any, and its accessible name says it in
 * words: "Data issues, 12 open".
 */
export default function AdminTabs({ current, openQuarantineCount }: AdminTabsProps) {
    const t = useTranslate();

    return (
        <Tabs
            label={t('admin.tabs.label')}
            items={ADMIN_PAGES.map(({ page, key }) => {
                const label = t(`admin.pages.${key}.title`);
                if (page !== 'data-issues') {
                    return { href: `/admin/${page}`, label, current: page === current };
                }
                const count = formatCount(openQuarantineCount);

                return {
                    href: `/admin/${page}`,
                    label,
                    current: page === current,
                    badge: { text: count, tone: openQuarantineCount > 0 ? ('alert' as const) : ('default' as const) },
                    accessibleName: t('admin.tabs.data_issues_open', { label, count }),
                };
            })}
        />
    );
}
