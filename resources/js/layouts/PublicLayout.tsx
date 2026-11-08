import type { ReactNode } from 'react';
import { Head, usePage } from '@inertiajs/react';
import AdminPreviewBar from '@/components/AdminPreviewBar';
import DashboardTabs from '@/components/DashboardTabs';
import EmptyState from '@/components/EmptyState';
import FilterBar from '@/components/FilterBar';
import PublicFooter from '@/components/PublicFooter';
import PublicHeader from '@/components/PublicHeader';
import TopBar from '@/components/TopBar';
import PageProviders from '@/layouts/PageProviders';
import { useHeadingFocus, useStickyHeaderOffset } from '@/layouts/shellEffects';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { DASHBOARD_PAGES, pageMeta } from '@/lib/pageMeta';

export type PublicLayoutProps = {
    children: ReactNode;
};

/**
 * The public dashboard's layout (ARCHITECTURE.md §9.0–9.1): skip link, the header with the KD seal
 * and "eDQA e-tool / Kaduna State", for a signed-in administrator the admin preview bar, the sticky
 * top bar (title, filters, page tabs), <main id="main">, and the footer ("Support: LAFIYA", figures
 * last updated). No Administration link, no login link, no export button (D-78, D-80).
 */
export default function PublicLayout({ children }: PublicLayoutProps) {
    return (
        <PageProviders>
            <PublicShell>{children}</PublicShell>
        </PageProviders>
    );
}

function PublicShell({ children }: PublicLayoutProps) {
    const t = useTranslate();
    const { component, url, props } = usePage();
    const meta = pageMeta(component);
    const title = meta === null ? null : t(meta.title);
    const path = url.split('?')[0] ?? url;
    const subtitle = meta?.subtitle === undefined ? null : t(meta.subtitle, { count: formatCount(props.summary?.facilities ?? 0) });
    const page = DASHBOARD_PAGES.find((name) => component === `dashboard/${name}`) ?? null;
    const titleRef = useHeadingFocus(component);
    const headerRef = useStickyHeaderOffset();

    return (
        <div className="flex min-h-svh flex-col">
            {title !== null && <Head title={title} />}
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-panel focus:px-4 focus:py-2 focus:shadow-overlay"
            >
                {t('common.skip_to_content')}
            </a>
            <PublicHeader />
            {props.auth.user !== null && <AdminPreviewBar />}
            <TopBar title={title} subtitle={subtitle} titleRef={titleRef} headerRef={headerRef}>
                {page !== null && props.filters !== undefined && props.filterOptions !== undefined && (
                    <>
                        <FilterBar filters={props.filters} options={props.filterOptions} path={path} />
                        <DashboardTabs current={page} filters={props.filters} lgaCount={props.summary?.lgas ?? 0} />
                    </>
                )}
            </TopBar>
            <main id="main" tabIndex={-1} className="mx-auto w-full max-w-[1320px] flex-1 px-4 pt-6 pb-16 rail:px-7">
                {page !== null && props.unpublished === true ? (
                    // D-77: nothing is public until a round is closed; never a page of zeros.
                    <section className="rounded-panel border border-rule bg-panel">
                        <EmptyState title={t('dashboard.unpublished.title')} description={t('dashboard.unpublished.description')} />
                    </section>
                ) : (
                    children
                )}
            </main>
            <PublicFooter updatedAt={props.figuresUpdatedAt ?? null} />
        </div>
    );
}
