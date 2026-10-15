import { useEffect, useRef, type ReactNode } from 'react';
import { Head, usePage } from '@inertiajs/react';
import DashboardTabs from '@/components/DashboardTabs';
import FilterBar from '@/components/FilterBar';
import Rail from '@/components/Rail';
import SyncStatusBar from '@/components/SyncStatusBar';
import TopBar from '@/components/TopBar';
import PageProviders from '@/layouts/PageProviders';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { DASHBOARD_PAGES, pageMeta } from '@/lib/pageMeta';

export type AppShellProps = {
    children: ReactNode;
};

/**
 * Every signed-in page (ARCHITECTURE.md §9.1): skip link, rail, ODK status, the sticky top bar
 * (title, subtitle, and on dashboard pages the filters and tabs), then <main id="main">.
 */
export default function AppShell({ children }: AppShellProps) {
    return (
        <PageProviders>
            <Shell>{children}</Shell>
        </PageProviders>
    );
}

function Shell({ children }: AppShellProps) {
    const t = useTranslate();
    const { component, url, props } = usePage();
    const meta = pageMeta(component);
    const title = meta === null ? null : t(meta.title);
    const path = url.split('?')[0] ?? url;
    const facilities = props.summary === undefined ? 0 : props.summary.facilities;
    const subtitle = meta?.subtitle === undefined ? null : t(meta.subtitle, { count: formatCount(facilities) });
    const dashboardPage = DASHBOARD_PAGES.find((page) => component === `dashboard/${page}`) ?? null;

    // A new page moves focus to its h1 (a screen reader hears where it landed); a filter change
    // reloads the same page and leaves focus on the select.
    const titleRef = useRef<HTMLHeadingElement>(null);
    const shownComponent = useRef(component);
    useEffect(() => {
        if (shownComponent.current !== component) {
            shownComponent.current = component;
            titleRef.current?.focus();
        }
    }, [component]);

    // Keyboard focus and in-page links must never land under the sticky top bar (WCAG 2.4.11):
    // scroll-padding-top follows its real height, which changes with the filters and the width.
    const headerRef = useRef<HTMLElement>(null);
    useEffect(() => {
        const header = headerRef.current;
        if (header === null || typeof ResizeObserver === 'undefined') {
            return;
        }
        const root = document.documentElement;
        const update = () => {
            const sticky = getComputedStyle(header).position === 'sticky';
            root.style.setProperty('--sticky-header-height', sticky ? `${header.offsetHeight + 12}px` : '0px');
        };
        const observer = new ResizeObserver(update);
        observer.observe(header);
        update();

        return () => {
            observer.disconnect();
            root.style.removeProperty('--sticky-header-height');
        };
    }, []);

    return (
        <div className="min-h-svh rail:grid rail:grid-cols-[210px_minmax(0,1fr)]">
            {title !== null && <Head title={title} />}
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-panel focus:px-4 focus:py-2 focus:shadow-panel"
            >
                {t('common.skip_to_content')}
            </a>
            <Rail />
            <div className="flex min-w-0 flex-col">
                {props.shell !== null && <SyncStatusBar status={props.shell} />}
                <TopBar title={title} subtitle={subtitle} titleRef={titleRef} headerRef={headerRef}>
                    {dashboardPage !== null && props.filters !== undefined && props.filterOptions !== undefined && (
                        <>
                            <FilterBar filters={props.filters} options={props.filterOptions} path={path} />
                            <DashboardTabs current={dashboardPage} filters={props.filters} lgaCount={props.summary?.lgas ?? 0} />
                        </>
                    )}
                </TopBar>
                <main id="main" tabIndex={-1} className="w-full max-w-[1280px] px-4 pt-5 pb-14 rail:px-6.5">
                    {children}
                </main>
            </div>
        </div>
    );
}
