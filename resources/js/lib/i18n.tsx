import { createContext, useContext, type ReactNode } from 'react';

/**
 * UI strings come from lang/en/*.php, shared by HandleInertiaRequests as `translations`
 * (CONVENTION.md §7, CLAUDE.md hard rule 10). Keys are "group.path", e.g. "auth.login.title".
 *
 * The layouts put the page's translations in an I18nProvider; components read them with
 * useTranslate(), so they render the same in a page and in a test.
 */
export type Translations = Record<string, unknown>;
export type Replacements = Record<string, string | number>;
export type Translate = (key: string, replacements?: Replacements) => string;

const I18nContext = createContext<Translations>({});

function lookup(translations: Translations, key: string): unknown {
    let value: unknown = translations;

    for (const part of key.split('.')) {
        if (typeof value !== 'object' || value === null || !(part in value)) {
            return undefined;
        }
        value = (value as Record<string, unknown>)[part];
    }

    return value;
}

/**
 * Looks up a dotted key and fills `:placeholder`s. An unknown key returns the key itself, so a
 * missing string is visible on the page rather than silently blank.
 */
export function translate(translations: Translations, key: string, replacements: Replacements = {}): string {
    const value = lookup(translations, key);
    if (typeof value !== 'string') {
        return key;
    }

    // Longest names first, so ":names" is never clobbered by ":name".
    return Object.keys(replacements)
        .sort((a, b) => b.length - a.length)
        .reduce((text, name) => text.replaceAll(`:${name}`, String(replacements[name])), value);
}

/** A list of strings, such as month names; empty when the key is missing or not such a list. */
export function translateList(translations: Translations, key: string): string[] {
    const value = lookup(translations, key);

    return Array.isArray(value) && value.every((item): item is string => typeof item === 'string') ? value : [];
}

export type I18nProviderProps = {
    translations: Translations;
    children: ReactNode;
};

export function I18nProvider({ translations, children }: I18nProviderProps) {
    return <I18nContext.Provider value={translations}>{children}</I18nContext.Provider>;
}

export function useTranslate(): Translate {
    const translations = useContext(I18nContext);

    return (key, replacements) => translate(translations, key, replacements);
}

export function useTranslationList(key: string): string[] {
    return translateList(useContext(I18nContext), key);
}
