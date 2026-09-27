import { describe, expect, it } from 'vitest';
import { cn, toUrl } from '@/lib/utils';

describe('cn', () => {
    it('keeps the last of two conflicting Tailwind classes', () => {
        expect(cn('p-2', 'p-4')).toBe('p-4');
    });

    it('drops falsy class values', () => {
        expect(cn('block', false, undefined, 'text-sm')).toBe('block text-sm');
    });
});

describe('toUrl', () => {
    it('returns a string href unchanged', () => {
        expect(toUrl('/settings/security')).toBe('/settings/security');
    });

    it('reads the url from a Wayfinder route definition', () => {
        expect(toUrl({ url: '/', method: 'get' })).toBe('/');
    });
});
