import { Link } from '@inertiajs/react';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { ADMIN_PAGES, type AdminPage } from '@/lib/pageMeta';

export type AdminRailProps = {
    current: AdminPage | null;
    /** Open quarantine records plus unreviewed deletions at source: the Data issues badge. */
    openIssueCount: number;
    /** All assessments, for the footer; null while unknown. */
    assessments: number | null;
};

/**
 * The admin layout's rail (ARCHITECTURE.md §9.1, §9.3): the KD seal and "eDQA e-tool / Kaduna
 * State", the five administration pages (Data issues with its open count as a badge, said in words:
 * "Data issues, 12 open"), "View public dashboard", and a footer with the assessment count and
 * "Support: HSDF". Below 760px it becomes a bar across the top and the footer is left out.
 */
export default function AdminRail({ current, openIssueCount, assessments }: AdminRailProps) {
    const t = useTranslate();
    const count = formatCount(openIssueCount);

    return (
        <aside className="flex items-center gap-4 overflow-x-auto bg-rail px-4 py-2.5 text-rail-ink rail:sticky rail:top-0 rail:h-svh rail:flex-col rail:items-stretch rail:gap-0 rail:overflow-visible rail:px-4 rail:py-0">
            <div className="flex shrink-0 items-center gap-3 rail:-mx-4 rail:border-b rail:border-rail-line rail:px-5 rail:py-5">
                <span aria-hidden="true" className="grid size-9 shrink-0 place-items-center rounded-full bg-rail-active font-display text-2xs font-bold tracking-[0.04em] text-panel ring-1 ring-rail-accent/70 ring-offset-2 ring-offset-rail">
                    {t('common.shell.seal')}
                </span>
                <p className="hidden leading-tight min-[420px]:block">
                    <span className="block font-display text-base font-semibold tracking-[-0.01em] text-panel">{t('common.shell.brand')}</span>
                    <span className="block text-2xs text-rail-muted">{t('common.shell.brand_place')}</span>
                </p>
            </div>
            <nav aria-label={t('admin.tabs.label')} className="rail:pt-4">
                <ul className="flex gap-1 rail:flex-col">
                    {ADMIN_PAGES.map(({ page, key }) => {
                        const label = t(`admin.pages.${key}.title`);
                        const issues = page === 'data-issues';

                        return (
                            <li key={page}>
                                <Link
                                    href={`/admin/${page}`}
                                    aria-current={page === current ? 'page' : undefined}
                                    aria-label={issues ? t('admin.tabs.data_issues_open', { label, count }) : undefined}
                                    className={`flex items-center gap-2.5 rounded-md px-2.5 py-2 text-sm whitespace-nowrap transition-colors duration-150 ${
                                        page === current
                                            ? 'bg-rail-active font-semibold text-panel forced-colors:forced-color-adjust-none forced-colors:bg-[Highlight] forced-colors:text-[HighlightText]'
                                            : 'text-rail-ink hover:bg-white/6 hover:text-panel'
                                    }`}
                                >
                                    <span className="flex-1">{label}</span>
                                    {issues && (
                                        <span aria-hidden="true" className={`rounded-full px-1.5 text-2xs font-semibold tabular-nums ${openIssueCount > 0 ? 'bg-bad text-panel' : 'bg-white/10 text-rail-ink'}`}>
                                            {count}
                                        </span>
                                    )}
                                </Link>
                            </li>
                        );
                    })}
                    <li className="rail:mt-3 rail:border-t rail:border-rail-line rail:pt-3">
                        <a href="/" className="flex items-center gap-2.5 rounded-md px-2.5 py-2 text-sm whitespace-nowrap text-rail-ink hover:bg-white/6 hover:text-panel">
                            {t('admin.rail.view_public')}
                        </a>
                    </li>
                </ul>
            </nav>
            {assessments !== null && (
                <div className="mt-auto hidden pb-5 text-2xs text-rail-muted rail:block">
                    <p className="border-t border-rail-line pt-4">
                        <span className="block font-display text-xl font-semibold tracking-[-0.02em] text-panel tabular-nums">{formatCount(assessments)}</span>
                        <span className="mt-1 block text-rail-ink">{t('common.shell.assessments_label')}</span>
                    </p>
                    <p className="mt-3">{t('common.shell.support')}</p>
                </div>
            )}
        </aside>
    );
}
