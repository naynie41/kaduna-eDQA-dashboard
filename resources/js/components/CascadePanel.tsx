import { useState } from 'react';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { useApiAction } from '@/lib/useApiAction';
import type { CascadeExport } from '@/types/admin';

export type CascadePanelProps = {
    /** docs/odk-cascade-media.md's link, or null to name the file instead. */
    howUrl: string | null;
};

/**
 * "Regenerate ODK facility file" (ARCHITECTURE.md §5.1, D-54, D-56): POST
 * /api/facility-list/export-cascade, then the counts and a signed download (30 minutes). Uploading
 * the files to ODK Central is manual for now, and the panel says so.
 */
export default function CascadePanel({ howUrl }: CascadePanelProps) {
    const t = useTranslate();
    const action = useApiAction<{ data: CascadeExport }>();
    const [result, setResult] = useState<CascadeExport | null>(null);

    const regenerate = async () => {
        const response = await action.run('POST', '/api/facility-list/export-cascade');
        if (response.ok) {
            setResult(response.data.data);
        }
    };

    return (
        <Panel labelledBy="odk-cascade-title">
            <PanelHeader id="odk-cascade-title" title={t('admin.odk.cascade.title')} description={t('admin.odk.cascade.description')} />
            <div className="flex flex-col gap-3 px-5 pb-5 text-[13.5px]">
                <p>
                    {howUrl === null ? (
                        t('admin.odk.cascade.how_file')
                    ) : (
                        <a href={howUrl} className="font-medium text-deep underline" target="_blank" rel="noreferrer">
                            {t('admin.odk.cascade.how')}
                        </a>
                    )}
                </p>
                <div>
                    <button
                        type="button"
                        onClick={() => void regenerate()}
                        disabled={action.pending}
                        className="min-h-11 rounded-md border border-deep px-4 text-[13.5px] font-medium text-deep hover:bg-wash disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {action.pending ? t('common.ui.working') : t('admin.odk.cascade.button')}
                    </button>
                </div>
                {action.error !== null && (
                    <p role="alert" className="rounded-md border border-bad bg-bad-bg px-3 py-2 text-[13px] text-bad">
                        {action.error}
                    </p>
                )}
                {result !== null && (
                    <div role="status" className="rounded-md border border-ok bg-ok-bg px-3 py-2">
                        <p className="text-ok">
                            {t('admin.odk.cascade.ready', { lgas: formatCount(result.lgas), wards: formatCount(result.wards), facilities: formatCount(result.facilities) })}
                        </p>
                        <a href={result.url} className="font-medium text-deep underline">
                            {t('admin.odk.cascade.download', { file: result.filename })}
                        </a>
                        <span className="block text-[12.5px] text-ink-2">{t('admin.odk.cascade.expiry')}</span>
                    </div>
                )}
            </div>
        </Panel>
    );
}
