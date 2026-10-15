import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { I18nProvider, translate, translateList, useTranslate } from '@/lib/i18n';

const translations = {
    auth: {
        login: { title: 'Sign in' },
        settings: { greeting: 'Signed in as :name with :names' },
    },
    common: { months_short: ['Jan', 'Feb'] },
};

describe('translate', () => {
    it('reads a dotted key', () => {
        expect(translate(translations, 'auth.login.title')).toBe('Sign in');
    });

    it('fills placeholders, longest name first', () => {
        expect(translate(translations, 'auth.settings.greeting', { name: 'Amina', names: 'two keys' })).toBe(
            'Signed in as Amina with two keys',
        );
    });

    it('returns the key when it is missing or not a string', () => {
        expect(translate(translations, 'auth.login.missing')).toBe('auth.login.missing');
        expect(translate(translations, 'auth.login')).toBe('auth.login');
    });
});

describe('translateList', () => {
    it('reads a list of strings, such as month names', () => {
        expect(translateList(translations, 'common.months_short')).toEqual(['Jan', 'Feb']);
    });

    it('returns an empty list for a missing key or one that is not a list of strings', () => {
        expect(translateList(translations, 'common.nothing')).toEqual([]);
        expect(translateList(translations, 'auth.login')).toEqual([]);
    });
});

describe('useTranslate', () => {
    function Title() {
        const t = useTranslate();

        return <h1>{t('auth.login.title')}</h1>;
    }

    it('reads the translations the nearest provider holds', () => {
        render(
            <I18nProvider translations={translations}>
                <Title />
            </I18nProvider>,
        );

        expect(screen.getByRole('heading', { name: 'Sign in' })).toBeTruthy();
    });

    it('shows the key, not a blank, outside a provider', () => {
        render(<Title />);

        expect(screen.getByRole('heading', { name: 'auth.login.title' })).toBeTruthy();
    });
});
