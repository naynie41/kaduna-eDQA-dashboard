/**
 * The top bar's title and subtitle for each Inertia page, as lang keys. Dashboard subtitles take
 * `:count` (facilities in view). A page not listed here shows no title in the top bar.
 */
export type PageMeta = {
    title: string;
    subtitle?: string;
    dashboard: boolean;
};

const META: Record<string, PageMeta> = {
    'dashboard/home': { title: 'dashboard.pages.home.title', subtitle: 'dashboard.pages.home.subtitle', dashboard: true },
    'dashboard/facility': { title: 'dashboard.pages.facility.title', subtitle: 'dashboard.pages.facility.subtitle', dashboard: true },
    'dashboard/lga': { title: 'dashboard.pages.lga.title', subtitle: 'dashboard.pages.lga.subtitle', dashboard: true },
    'dashboard/monitor': { title: 'dashboard.pages.monitor.title', subtitle: 'dashboard.pages.monitor.subtitle', dashboard: true },
    'dashboard/visuals': { title: 'dashboard.pages.visuals.title', subtitle: 'dashboard.pages.visuals.subtitle', dashboard: true },
    'dashboard/plan': { title: 'dashboard.pages.plan.title', subtitle: 'dashboard.pages.plan.subtitle', dashboard: true },
    'admin/index': { title: 'common.pages.admin.title', subtitle: 'common.pages.admin.subtitle', dashboard: false },
    'settings/security': { title: 'auth.settings.title', dashboard: false },
    'dev/components': { title: 'common.pages.dev.title', dashboard: false },
};

export function pageMeta(component: string): PageMeta | null {
    return META[component] ?? null;
}

/** The dashboard pages in tab order. */
export const DASHBOARD_PAGES = ['home', 'facility', 'lga', 'monitor', 'visuals', 'plan'] as const;

export type DashboardPage = (typeof DASHBOARD_PAGES)[number];
