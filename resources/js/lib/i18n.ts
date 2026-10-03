import { usePage } from '@inertiajs/react';

/**
 * UI strings come from lang/en/*.php, shared by HandleInertiaRequests as `translations`
 * (CONVENTION.md §7, CLAUDE.md hard rule 10). Keys are "group.path", e.g. "auth.login.title".
 */
export type Translations = Record<string, unknown>;
export type Replacements = Record<string, string | number>;
export type Translate = (key: string, replacements?: Replacements) => string;

/**
 * Looks up a dotted key and fills `:placeholder`s. An unknown key returns the key itself, so a
 * missing string is visible on the page rather than silently blank.
 */
export function translate(translations: Translations, key: string, replacements: Replacements = {}): string {
    let value: unknown = translations;

    for (const part of key.split('.')) {
        if (typeof value !== 'object' || value === null || !(part in value)) {
            return key;
        }
        value = (value as Record<string, unknown>)[part];
    }

    if (typeof value !== 'string') {
        return key;
    }

    // Longest names first, so ":names" is never clobbered by ":name".
    return Object.keys(replacements)
        .sort((a, b) => b.length - a.length)
        .reduce((text, name) => text.replaceAll(`:${name}`, String(replacements[name])), value);
}

export function useTranslate(): Translate {
    const { translations } = usePage().props;

    return (key, replacements) => translate(translations, key, replacements);
}
