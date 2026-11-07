import { useState } from 'react';
import { router } from '@inertiajs/react';
import FacilityDetailView from '@/components/FacilityDetailView';
import { useTranslate } from '@/lib/i18n';
import type { FacilityDetail } from '@/types/dashboard';

export type AdminFacilityProps = {
    detail: FacilityDetail;
};

/**
 * The admin facility view, /admin/facilities/{id} (ARCHITECTURE.md §9.2.7): everything the public
 * drawer leaves out, for every round: the assessor, flags, attachments, the audit trail, the source
 * and its link, and "Correct this assessment" while the round is open (D-58). After a correction
 * the detail is reloaded and correction mode starts afresh from the new values.
 */
export default function AdminFacility({ detail }: AdminFacilityProps) {
    const t = useTranslate();
    const [version, setVersion] = useState(0);
    const reload = () => router.reload({ only: ['detail'], onSuccess: () => setVersion((n) => n + 1) });

    return (
        <article className="flex max-w-4xl flex-col gap-4">
            <header>
                <h2 className="text-lg font-semibold">{detail.facility.name}</h2>
                <p className="text-sm text-ink-2">{t('dashboard.drawer.place', { lga: detail.facility.lga, ward: detail.facility.ward })}</p>
            </header>
            <FacilityDetailView key={version} detail={detail} onCorrected={reload} />
        </article>
    );
}
