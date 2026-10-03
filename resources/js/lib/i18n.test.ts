import { describe, expect, it } from 'vitest';
import { translate } from '@/lib/i18n';

const translations = {
    auth: {
        login: { title: 'Sign in' },
        settings: { greeting: 'Signed in as :name with :names' },
    },
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
