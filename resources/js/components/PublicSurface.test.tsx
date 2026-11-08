import type { ReactNode } from 'react';
import { screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import PublicLayout from '@/layouts/PublicLayout';
import Login from '@/pages/auth/login';
import Monitor from '@/pages/dashboard/monitor';
import { axeCheck, renderUi, testTranslations } from '@/test/render';
import type { Translations } from '@/lib/i18n';

// D-78: the public site never links to /login or to the admin area. The public layout shows the
// admin preview bar to a signed-in administrator only; the account menu is the admin layout's; the
// login page links to nothing but "Forgot password".

type User = { id: number; name: string; email: string };

const translations: Translations = {
    ...testTranslations,
    common: {
        ...(testTranslations.common as Record<string, unknown>),
        skip_to_content: 'Skip to content',
        shell: { seal: 'KD', brand: 'eDQA e-tool', brand_place: 'Kaduna State', assessments_label: 'assessments', support: 'Support: LAFIYA' },
    },
    dashboard: {
        pages: { home: { title: 'Home', subtitle: ':count facilities' }, facility: { title: 'Facility view' }, lga: { title: 'LGA view' }, monitor: { title: 'Monitor' }, visuals: { title: 'Visuals' }, plan: { title: 'Implementation plan' } },
        tabs: { label: 'Dashboard pages', lga_badge: ':count LGAs' },
        filters: {
            label: 'Filters', year: 'Year', lga: 'LGA', level: 'Level', owner: 'Owner',
            all_years: 'All years', all_lgas: 'All LGAs', all_levels: 'All levels', all_owners: 'Public and private', clear: 'Clear filters',
            duration: 'Duration', all_quarters: 'All quarters', duration_quarters: 'Quarters', duration_months: 'Months', quarter_option: 'Q:quarter', month_option: ':month (Q:quarter)',
            cleared: 'Filters cleared.', announce: 'Showing :count facilities. Average :average.', announce_none: 'No facilities match these filters.',
        },
        footer: { updated: 'Figures last updated' },
        unpublished: { title: 'No published results yet.', description: 'Results appear once a round is closed.' },
    },
    admin: {
        preview: { label: 'Admin preview', text: 'Admin preview — includes the open round', back: 'Back to admin' },
    },
    auth: {
        fields: { email: 'Email address', password: 'Password' },
        login: { title: 'Sign in', intro: 'Sign in with your administrator account.', submit: 'Sign in', forgot: 'Forgotten your password?' },
    },
};

const page = vi.hoisted(() => ({
    component: 'dashboard/home',
    url: '/',
    props: {
        auth: { user: null as User | null },
        translations: {} as Record<string, unknown>,
        filters: { year: null, quarter: null, month: null, lga: null, level: null, owner: null },
        filterOptions: { rounds: [{ year: 2026, quarter: 1 }], lgas: [{ code: 'ZAR', name: 'Zaria' }], levels: [], owners: [] },
        summary: { facilities: 7, lgas: 3, overall: { value: 78.7, display: '78.7', headline: '79', band: 'review', bandLabel: 'Review' } },
        unpublished: false,
        figuresUpdatedAt: '2026-04-03T10:00:00+01:00' as string | null,
    },
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();

    // Deferred blocks show their loading fallback: what matters here is which blocks exist.
    return { ...actual, Head: () => null, usePage: () => page, Deferred: ({ fallback }: { fallback: ReactNode }) => fallback };
});

function signIn(): void {
    page.props.auth.user = { id: 1, name: 'Amina Bello', email: 'amina@example.test' };
}

afterEach(() => {
    page.props.auth.user = null;
    page.props.unpublished = false;
    page.props.figuresUpdatedAt = '2026-04-03T10:00:00+01:00';
});

function hrefs(): string[] {
    return Array.from(document.body.querySelectorAll('a')).map((link) => link.getAttribute('href') ?? '');
}

function renderPublic() {
    page.props.translations = translations;

    return renderUi(<PublicLayout>{<p>Page body</p>}</PublicLayout>, translations);
}

describe('PublicLayout', () => {
    it('shows a visitor the header, the filters and tabs, the page and the footer, with no way into the admin area', async () => {
        renderPublic();

        expect(screen.getByRole('link', { name: /^eDQA e-tool/ }).getAttribute('href')).toBe('/');
        expect(screen.getByRole('heading', { level: 1, name: 'Home' })).toBeTruthy();
        expect(screen.getByRole('navigation', { name: 'Dashboard pages' })).toBeTruthy();
        expect(screen.getByRole('main').textContent).toContain('Page body');
        const footer = screen.getByRole('contentinfo');
        expect(footer.textContent).toContain('Support: LAFIYA');
        expect(footer.textContent).toContain('Figures last updated 3 Apr 2026');
        expect(screen.queryByRole('region', { name: 'Admin preview' })).toBeNull();
        expect(hrefs().some((href) => href.startsWith('/admin') || href.startsWith('/login'))).toBe(false);
        expect(screen.queryByRole('navigation', { name: 'Account' })).toBeNull();
        expect(await axeCheck(document.body)).toHaveNoViolations();
    });

    it('says "No published results yet" instead of the page while no round is closed', () => {
        page.props.unpublished = true;
        page.props.figuresUpdatedAt = null;
        renderPublic();

        expect(screen.getByRole('main').textContent).toContain('No published results yet.');
        expect(screen.getByRole('main').textContent).not.toContain('Page body');
        expect(screen.getByRole('contentinfo').textContent).not.toContain('Figures last updated');
    });

    it('shows a signed-in administrator the preview bar before <main>, with the way back to admin', () => {
        signIn();
        renderPublic();

        const bar = screen.getByRole('region', { name: 'Admin preview' });
        expect(bar.textContent).toContain('Admin preview — includes the open round');
        expect(within(bar).getByRole('link', { name: 'Back to admin' }).getAttribute('href')).toBe('/admin');
        expect(bar.compareDocumentPosition(screen.getByRole('main')) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });
});

describe('Login page', () => {
    it('links to nothing but "Forgot password"', () => {
        renderUi(<Login />, translations);

        expect(hrefs()).toEqual(['/forgot-password']);
        expect(screen.getByRole('link', { name: 'Forgotten your password?' })).toBeTruthy();
    });
});

describe('Monitor', () => {
    const monitorTranslations: Translations = {
        ...translations,
        dashboard: { ...(translations.dashboard as Record<string, unknown>), monitor: { sources_title: 'Counts by source', sources_description: 'Assessments in view by source.' } },
    };

    // D-81: the counts by source are the admin preview's only; the server never sends them to a
    // visitor, so a visitor's page must not show the panel (it would wait for them for ever).
    it('shows a visitor no counts-by-source panel, and nothing left loading for it', () => {
        renderUi(<Monitor />, monitorTranslations);

        expect(screen.queryByRole('heading', { name: 'Counts by source' })).toBeNull();
    });

    it('shows the admin preview the counts-by-source panel', () => {
        signIn();
        renderUi(<Monitor />, monitorTranslations);

        expect(screen.getByRole('heading', { name: 'Counts by source' })).toBeTruthy();
    });
});
