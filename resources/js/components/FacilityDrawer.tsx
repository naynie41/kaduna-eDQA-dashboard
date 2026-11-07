import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import Drawer from '@/components/Drawer';
import PublicFacilityView from '@/components/PublicFacilityView';
import Skeleton from '@/components/Skeleton';
import { ApiError, getJson } from '@/lib/api';
import { useTranslate } from '@/lib/i18n';
import { show } from '@/routes/public/api/facilities';
import type { PublicFacilityDetail } from '@/types/dashboard';

export type FacilityDrawerProps = {
    facilityId: number;
    /** Known when opened from a table row: the heading names the facility while it loads. */
    name?: string | undefined;
    /** The dashboard's year and quarter, so the drawer opens on the round the page shows. */
    year?: string | undefined;
    quarter?: string | undefined;
    onClose: () => void;
};

type Result = { key: string; detail: PublicFacilityDetail } | { key: string; error: number };

/**
 * The public facility drawer (ARCHITECTURE.md §9.2.7): fetches GET /api/public/facilities/{id},
 * and only that (SECURITY.md §3.1), and shows it in a Drawer. Loading and failure are said in
 * words; a failure can be retried. A signed-in administrator previewing the site also gets a link
 * to the admin facility view, where flags, attachments, the audit trail and corrections are.
 */
export default function FacilityDrawer({ facilityId, name, year, quarter, onClose }: FacilityDrawerProps) {
    const t = useTranslate();
    const { user } = usePage().props.auth;
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
        getJson<{ data: PublicFacilityDetail }>(url, controller.signal)
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
                    <p className="text-sm text-ink-2">{t('dashboard.drawer.loading')}</p>
                    <Skeleton className="h-24 w-full" />
                    <Skeleton className="h-64 w-full" />
                </div>
            ) : 'error' in current ? (
                <div role="alert" className="flex flex-col items-start gap-3 text-sm">
                    <p>{current.error === 404 ? t('dashboard.drawer.not_found') : t('dashboard.drawer.error')}</p>
                    {current.error !== 404 && (
                        <button type="button" onClick={() => setAttempt((n) => n + 1)} className="btn btn-secondary min-h-9">
                            {t('dashboard.drawer.retry')}
                        </button>
                    )}
                </div>
            ) : (
                <>
                    {user !== null && (
                        <p className="mb-4 text-sm">
                            <a href={`/admin/facilities/${facilityId}`} className="font-medium text-deep underline">
                                {t('dashboard.drawer.admin_view')}
                            </a>
                        </p>
                    )}
                    <PublicFacilityView detail={current.detail} />
                </>
            )}
        </Drawer>
    );
}
