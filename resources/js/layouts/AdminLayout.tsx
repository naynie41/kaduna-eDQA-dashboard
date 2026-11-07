import type { ReactNode } from 'react';
import { Head, usePage } from '@inertiajs/react';
import AccountMenu from '@/components/AccountMenu';
import AdminRail from '@/components/AdminRail';
import StaleIngestionBanner from '@/components/StaleIngestionBanner';
import SyncStatusBar from '@/components/SyncStatusBar';
import TopBar from '@/components/TopBar';
import PageProviders from '@/layouts/PageProviders';
import { useHeadingFocus, useStickyHeaderOffset } from '@/layouts/shellEffects';
import { useTranslate } from '@/lib/i18n';
import { ADMIN_PAGES, pageMeta } from '@/lib/pageMeta';

export type AdminLayoutProps = {
    children: ReactNode;
};

/**
 * The admin area's layout (ARCHITECTURE.md §9.0–9.1, §9.3): skip link, the rail (the admin pages
 * and "View public dashboard"), the full per-source status bar, the sticky top bar with the title
 * and the account menu (security settings, log out), then <main id="main">, which opens with the
 * stale-ingestion banner when it applies (SECURITY.md §12).
 */
export default function AdminLayout({ children }: AdminLayoutProps) {
    return (
        <PageProviders>
            <AdminShell>{children}</AdminShell>
        </PageProviders>
    );
}

function AdminShell({ children }: AdminLayoutProps) {
    const t = useTranslate();
    const { component, props } = usePage();
    const meta = pageMeta(component);
    const title = meta === null ? null : t(meta.title);
    const subtitle = meta?.subtitle === undefined ? null : t(meta.subtitle);
    // The admin facility view sits under the Facility list.
    const adminComponent = component === 'admin/facility' ? 'admin/facilities' : component;
    const current = ADMIN_PAGES.find(({ page }) => adminComponent === `admin/${page}`)?.page ?? null;
    const shell = props.shell ?? null;
    const admin = props.admin ?? null;
    const titleRef = useHeadingFocus(component);
    const headerRef = useStickyHeaderOffset();

    return (
        <div className="min-h-svh rail:grid rail:grid-cols-[232px_minmax(0,1fr)]">
            {title !== null && <Head title={title} />}
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-panel focus:px-4 focus:py-2 focus:shadow-overlay"
            >
                {t('common.skip_to_content')}
            </a>
            <AdminRail current={current} openIssueCount={admin?.openIssueCount ?? 0} assessments={shell?.assessments ?? null} />
            <div className="flex min-w-0 flex-col">
                {shell !== null && <SyncStatusBar status={shell} />}
                <TopBar title={title} subtitle={subtitle} titleRef={titleRef} headerRef={headerRef} actions={<AccountMenu />} />
                <main id="main" tabIndex={-1} className="w-full max-w-[1320px] px-4 pt-6 pb-16 rail:px-7">
                    {admin !== null && <StaleIngestionBanner stale={admin.staleIngestion} />}
                    {children}
                </main>
            </div>
        </div>
    );
}
