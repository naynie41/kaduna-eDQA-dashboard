import { useEffect, useState } from 'react';
import Drawer from '@/components/Drawer';
import FacilityDetailView from '@/components/FacilityDetailView';
import Skeleton from '@/components/Skeleton';
import { ApiError, getJson } from '@/lib/api';
import { useTranslate } from '@/lib/i18n';
import { show } from '@/routes/api/facilities';
import type { FacilityDetail } from '@/types/dashboard';

export type FacilityDrawerProps = {
    facilityId: number;
    /** Known when opened from a table row: the heading names the facility while it loads. */
    name?: string | undefined;
    /** The dashboard's year and quarter, so the drawer opens on the round the page shows. */
    year?: string | undefined;
    quarter?: string | undefined;
    onClose: () => void;
};

type Result = { key: string; detail: FacilityDetail } | { key: string; error: number };

/**
 * The facility detail drawer (ARCHITECTURE.md §9.2.7): fetches GET /api/facilities/{id} and shows
 * it in a Drawer. Loading and failure are said in words; a failure can be retried.
 */
export default function FacilityDrawer({ facilityId, name, year, quarter, onClose }: FacilityDrawerProps) {
    const t = useTranslate();
    const [attempt, setAttempt] = useState(0);
    const [result, setResult] = useState<Result | null>(null);
    const query: Record<string, string> = {};
    if (year !== undefined) {
        query.year = year;
    }
    if (quarter !== undefined) {
        query.quarter = quarter;
    }
    const url = show.url(facilityId, { query });
    const key = `${url}#${attempt}`;

    useEffect(() => {
        const controller = new AbortController();
        getJson<{ data: FacilityDetail }>(url, controller.signal)
            .then((response) => setResult({ key, detail: response.data }))
            .catch((error: unknown) => {
                if (!controller.signal.aborted) {
                    setResult({ key, error: error instanceof ApiError ? error.status : 0 });
                }
            });

        return () => controller.abort();
    }, [url, key]);

    const current = result?.key === key ? result : null;
    const detail = current !== null && 'detail' in current ? current.detail : null;
    const title = detail?.facility.name ?? name ?? t('dashboard.drawer.loading_title');
    const subtitle = detail === null ? undefined : t('dashboard.drawer.place', { lga: detail.facility.lga, ward: detail.facility.ward });

    return (
        <Drawer title={title} subtitle={subtitle} closeLabel={t('dashboard.drawer.close')} onClose={onClose}>
            {current === null ? (
                <div role="status" className="flex flex-col gap-3">
                    <p className="text-[13px] text-ink-2">{t('dashboard.drawer.loading')}</p>
                    <Skeleton className="h-24 w-full" />
                    <Skeleton className="h-64 w-full" />
                </div>
            ) : 'error' in current ? (
                <div role="alert" className="flex flex-col items-start gap-3 text-[13px]">
                    <p>{current.error === 404 ? t('dashboard.drawer.not_found') : t('dashboard.drawer.error')}</p>
                    {current.error !== 404 && (
                        <button type="button" onClick={() => setAttempt((n) => n + 1)} className="min-h-9 rounded-md border border-rule bg-panel px-3 hover:bg-paper">
                            {t('dashboard.drawer.retry')}
                        </button>
                    )}
                </div>
            ) : (
                // key: a reload after a correction starts correction mode afresh from the new values.
                <FacilityDetailView key={key} detail={current.detail} onCorrected={() => setAttempt((n) => n + 1)} />
            )}
        </Drawer>
    );
}
