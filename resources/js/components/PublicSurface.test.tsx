import { screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Rail from '@/components/Rail';
import TopBar from '@/components/TopBar';
import Login from '@/pages/auth/login';
import { renderUi, testTranslations } from '@/test/render';

// D-78: the public site never links to /login or to the admin area. The rail and the top bar show
// the admin workspace and the account links to a signed-in administrator only; the login page links
// to nothing but "Forgot password".

const page = vi.hoisted(() => ({ url: '/', props: { auth: { user: null as { id: number; name: string; email: string } | null }, shell: null } }));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();

    return { ...actual, Head: () => null, usePage: () => page };
});

const translations = {
    ...testTranslations,
    common: {
        ...(testTranslations.common as Record<string, unknown>),
        nav: { label: 'Main', dashboard: 'Dashboard', administration: 'Administration' },
        shell: { seal: 'KD', brand: 'eDQA e-tool', brand_place: 'Kaduna State', assessments_label: 'assessments', support: 'Support: HSDF' },
        user_menu: { label: 'Account', signed_in_as: 'Signed in as :name', security: 'Security', log_out: 'Log out' },
    },
    auth: {
        fields: { email: 'Email address', password: 'Password' },
        login: { title: 'Sign in', intro: 'Sign in with your administrator account.', submit: 'Sign in', forgot: 'Forgotten your password?' },
    },
};

function signIn(): void {
    page.props.auth.user = { id: 1, name: 'Amina Bello', email: 'amina@example.test' };
}

afterEach(() => {
    page.url = '/';
    page.props.auth.user = null;
});

function hrefs(container: HTMLElement): string[] {
    return Array.from(container.querySelectorAll('a')).map((link) => link.getAttribute('href') ?? '');
}

describe('Rail', () => {
    it('shows a visitor the dashboard only: no link to the admin area', () => {
        const { container } = renderUi(<Rail />, translations);

        const nav = screen.getByRole('navigation', { name: 'Main' });
        expect(within(nav).getByRole('link', { name: 'Dashboard' }).getAttribute('href')).toBe('/');
        expect(within(nav).queryByRole('link', { name: 'Administration' })).toBeNull();
        expect(hrefs(container).some((href) => href.startsWith('/admin') || href.startsWith('/login'))).toBe(false);
    });

    it('shows a signed-in administrator both workspaces, marking the current one', () => {
        signIn();
        page.url = '/admin/sources';
        renderUi(<Rail />, translations);

        const nav = screen.getByRole('navigation', { name: 'Main' });
        expect(within(nav).getByRole('link', { name: 'Administration' }).getAttribute('aria-current')).toBe('page');
        expect(within(nav).getByRole('link', { name: 'Dashboard' }).getAttribute('aria-current')).toBeNull();
    });
});

describe('TopBar', () => {
    it('has no account links for a visitor', () => {
        const { container } = renderUi(<TopBar title="Home" />, translations);

        expect(screen.queryByRole('navigation', { name: 'Account' })).toBeNull();
        expect(hrefs(container)).toEqual([]);
    });

    it('has the security page and log out for a signed-in administrator', () => {
        signIn();
        renderUi(<TopBar title="Home" />, translations);

        const account = screen.getByRole('navigation', { name: 'Account' });
        expect(within(account).getByRole('link', { name: 'Security' }).getAttribute('href')).toBe('/admin/settings/security');
        expect(within(account).getByRole('button', { name: 'Log out' })).toBeTruthy();
    });
});

describe('Login page', () => {
    it('links to nothing but "Forgot password"', () => {
        const { container } = renderUi(<Login />, translations);

        expect(hrefs(container)).toEqual(['/forgot-password']);
        expect(screen.getByRole('link', { name: 'Forgotten your password?' })).toBeTruthy();
    });
});
