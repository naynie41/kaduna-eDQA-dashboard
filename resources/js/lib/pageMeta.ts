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
    'admin/odk': { title: 'admin.pages.odk.title', subtitle: 'admin.pages.odk.subtitle', dashboard: false },
    'admin/data-issues': { title: 'admin.pages.data_issues.title', subtitle: 'admin.pages.data_issues.subtitle', dashboard: false },
    'admin/rounds': { title: 'admin.pages.rounds.title', subtitle: 'admin.pages.rounds.subtitle', dashboard: false },
    'admin/facilities': { title: 'admin.pages.facilities.title', subtitle: 'admin.pages.facilities.subtitle', dashboard: false },
    'admin/scoring-rules': { title: 'admin.pages.scoring_rules.title', subtitle: 'admin.pages.scoring_rules.subtitle', dashboard: false },
    'settings/security': { title: 'auth.settings.title', dashboard: false },
    'dev/components': { title: 'common.pages.dev.title', dashboard: false },
};

export function pageMeta(component: string): PageMeta | null {
    return META[component] ?? null;
}

/** The administration pages in tab order (ARCHITECTURE.md §9.3): path segment and lang key. */
export const ADMIN_PAGES = [
    { page: 'odk', key: 'odk' },
    { page: 'data-issues', key: 'data_issues' },
    { page: 'rounds', key: 'rounds' },
    { page: 'facilities', key: 'facilities' },
    { page: 'scoring-rules', key: 'scoring_rules' },
] as const;

export type AdminPage = (typeof ADMIN_PAGES)[number]['page'];

/** The dashboard pages in tab order. */
export const DASHBOARD_PAGES = ['home', 'facility', 'lga', 'monitor', 'visuals', 'plan'] as const;

export type DashboardPage = (typeof DASHBOARD_PAGES)[number];
