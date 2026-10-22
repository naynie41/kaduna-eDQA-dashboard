import { Link } from '@inertiajs/react';
import { formatDateTime } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import type { AdminShell } from '@/types/shell';

export type StaleIngestionBannerProps = {
    stale: AdminShell['staleIngestion'];
};

/**
 * SECURITY.md §12 on every admin page: a round is open and no ODK pull has succeeded for a day
 * (or ever). It says so in words and links to the ODK pull page; the shape and the words carry
 * it, not the colour alone. Nothing renders while ingestion is current.
 */
export default function StaleIngestionBanner({ stale }: StaleIngestionBannerProps) {
    const t = useTranslate();
    const months = useTranslationList('common.months_short');
    if (!stale.stale || stale.round === null) {
        return null;
    }
    const round = { quarter: stale.round.quarter, year: stale.round.year };
    const body =
        stale.lastSuccessAt === null
            ? t('admin.stale.body_never', round)
            : t('admin.stale.body', { ...round, time: months.length === 12 ? formatDateTime(stale.lastSuccessAt, months) : stale.lastSuccessAt });

    return (
        <section aria-labelledby="stale-ingestion-title" className="mb-5 rounded-md border border-warn-fill bg-warn-bg px-4 py-3 text-warn forced-colors:border-[CanvasText]">
            <h2 id="stale-ingestion-title" className="flex items-center gap-2 text-[14px] font-semibold">
                <span aria-hidden="true">▲</span>
                {t('admin.stale.title', { hours: stale.hours })}
            </h2>
            <p className="mt-1 text-[13px] text-ink">{body}</p>
            <p className="mt-1.5 text-[13px]">
                <Link href="/admin/odk" className="font-medium text-deep underline">
                    {t('admin.stale.link')}
                </Link>
            </p>
        </section>
    );
}
