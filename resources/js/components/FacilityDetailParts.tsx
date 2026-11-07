import type { ReactNode } from 'react';
import type { Translate } from '@/lib/i18n';
import type { ItemResult, ItemValue } from '@/types/dashboard';

/**
 * What the public facility drawer (PublicFacilityView) and the admin facility view
 * (FacilityDetailView) both show: a titled section, a term and its value, and an item's result.
 */

/** A value in words: "Pass", "N/A", or for a question answered as several checks "2 of 3". */
export function valueLabel(t: Translate, value: ItemValue, total: number | null): string {
    return total !== null && value !== 'na' && /^\d+$/.test(value) ? t('dashboard.drawer.results.count', { passed: value, total: String(total) }) : t(`dashboard.drawer.results.${value}`);
}

export function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section>
            <h3 className="mb-2 text-base">{title}</h3>
            {children}
        </section>
    );
}

export function Term({ label, children }: { label: string; children: ReactNode }) {
    return (
        <>
            <dt className="text-ink-2">{label}</dt>
            <dd>{children}</dd>
        </>
    );
}

const RESULT_TONE: Record<ItemResult, string> = { pass: 'text-ok', fail: 'text-bad', na: 'text-ink-2' };

/**
 * Pass / Fail / N/A as an icon and a word: the word carries the meaning, never the colour. A
 * question answered as several checks reads "2 of 3", toned by how many passed.
 */
export function Result({ result, checks, t }: { result: ItemValue | null; checks: { passed: number; total: number } | null; t: Translate }) {
    if (result === null) {
        return <span className="shrink-0 text-ink-2">{t('dashboard.drawer.results.unknown')}</span>;
    }
    if (checks !== null && result !== 'na') {
        const tone = checks.passed === checks.total ? 'text-ok' : checks.passed === 0 ? 'text-bad' : 'text-warn';

        return <span className={`shrink-0 font-medium tabular-nums ${tone}`}>{valueLabel(t, result, checks.total)}</span>;
    }
    if (result !== 'pass' && result !== 'fail' && result !== 'na') {
        return <span className="shrink-0 text-ink-2">{t('dashboard.drawer.results.unknown')}</span>;
    }

    return (
        <span className={`inline-flex shrink-0 items-center gap-1 font-medium ${RESULT_TONE[result]}`}>
            <svg viewBox="0 0 16 16" width={14} height={14} aria-hidden="true" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
                {result === 'pass' && <path d="M3 8.5l3 3 7-7" />}
                {result === 'fail' && <path d="M4 4l8 8M12 4l-8 8" />}
                {result === 'na' && <path d="M4 8h8" />}
            </svg>
            {t(`dashboard.drawer.results.${result}`)}
        </span>
    );
}
