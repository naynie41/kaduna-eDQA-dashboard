import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';

export type PaginationProps = {
    page: number;
    perPage: number;
    total: number;
    onPage: (page: number) => void;
};

/** The page numbers to offer: first, last, and the current one with its neighbours. */
function pagesAround(page: number, last: number): (number | 'gap')[] {
    const wanted = [...new Set([1, page - 1, page, page + 1, last])].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b);
    const out: (number | 'gap')[] = [];
    wanted.forEach((p, i) => {
        const previous = wanted[i - 1];
        if (previous !== undefined && p - previous > 1) {
            out.push('gap');
        }
        out.push(p);
    });

    return out;
}

/**
 * "Showing 51–100 of 2,184 facilities", then Previous, page numbers and Next as labelled buttons
 * in a navigation landmark; the current page has aria-current. Paging is the server's.
 */
export default function Pagination({ page, perPage, total, onPage }: PaginationProps) {
    const t = useTranslate();
    const last = Math.max(1, Math.ceil(total / perPage));
    const from = total === 0 ? 0 : (page - 1) * perPage + 1;
    const to = Math.min(total, page * perPage);
    const base = 'min-h-9 min-w-9 rounded-md border px-2.5 text-[13px]';
    const button = `${base} border-rule bg-panel hover:bg-paper disabled:cursor-not-allowed disabled:text-ink-2 disabled:hover:bg-panel`;
    const current = `${base} border-deep bg-deep font-medium text-panel`;

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
            <p className="text-[12.5px] text-ink-2">
                {total === 0 || from > total
                    ? t('dashboard.facility.showing_none')
                    : t('dashboard.facility.showing', { from: formatCount(from), to: formatCount(to), total: formatCount(total) })}
            </p>
            {last > 1 && (
                <nav aria-label={t('dashboard.facility.pages')}>
                    <ul className="flex flex-wrap items-center gap-1">
                        <li>
                            <button type="button" className={button} disabled={page <= 1} onClick={() => onPage(page - 1)}>
                                {t('dashboard.facility.previous')}
                            </button>
                        </li>
                        {pagesAround(page, last).map((p, i) =>
                            p === 'gap' ? (
                                <li key={`gap-${i}`} aria-hidden="true" className="px-1 text-ink-2">
                                    …
                                </li>
                            ) : (
                                <li key={p}>
                                    <button
                                        type="button"
                                        aria-label={t('dashboard.facility.page', { page: p })}
                                        aria-current={p === page ? 'page' : undefined}
                                        className={`${p === page ? current : button} tabular-nums`}
                                        onClick={() => onPage(p)}
                                    >
                                        {formatCount(p)}
                                    </button>
                                </li>
                            ),
                        )}
                        <li>
                            <button type="button" className={button} disabled={page >= last} onClick={() => onPage(page + 1)}>
                                {t('dashboard.facility.next')}
                            </button>
                        </li>
                    </ul>
                </nav>
            )}
        </div>
    );
}
