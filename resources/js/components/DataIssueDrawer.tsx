import { useEffect, useState, type ReactNode } from 'react';
import Drawer from '@/components/Drawer';
import { SeverityTag } from '@/components/RuleSummary';
import { getJson } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import type { DataIssueDetail } from '@/types/admin';

export type DataIssueDrawerProps = {
    recordId: number;
    instanceId: string;
    onClose: () => void;
};

const EVENTS = ['created', 'corrected', 'rejected', 'superseded', 'updated'];

/**
 * One quarantined record in full (D-57): every failure, what the portal read (the parsed
 * values), the corrections so far, the submission exactly as ODK Central sent it (pretty JSON,
 * collapsed until asked for, never editable: hard rule 8), and its history from the audit log.
 */
export default function DataIssueDrawer({ recordId, instanceId, onClose }: DataIssueDrawerProps) {
    const t = useTranslate();
    const months = useTranslationList('common.months_short');
    const when = (value: string | null) => (value === null ? '' : months.length === 12 ? formatDateTime(value, months) : value);
    const [detail, setDetail] = useState<DataIssueDetail | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        getJson<{ data: DataIssueDetail }>(`/api/quarantine/${recordId}`, controller.signal)
            .then((response) => setDetail(response.data))
            .catch(() => undefined);

        return () => controller.abort();
    }, [recordId]);

    return (
        <Drawer title={t('admin.issues.drawer.title', { instance: instanceId })} closeLabel={t('admin.issues.drawer.close')} onClose={onClose}>
            {detail === null ? (
                <p className="text-[13px] text-ink-2">{t('admin.issues.drawer.loading')}</p>
            ) : (
                <div className="flex flex-col gap-5 text-[13px]">
                    <Section title={t('admin.issues.drawer.failures')}>
                        <ul className="flex flex-col gap-2">
                            {detail.failures.map((failure, i) => (
                                <li key={`${failure.code}-${i}`} className="flex flex-col gap-1">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <SeverityTag severity={failure.severity} label={t(`admin.issues.summary.${failure.severity === 'soft' ? 'soft' : 'hard'}`)} />
                                        <span className="font-medium">{failure.name}</span>
                                        <code className="text-[11.5px] text-ink-2">{failure.code}</code>
                                    </span>
                                    <code className="rounded bg-paper px-2 py-1 text-[12.5px] break-words whitespace-pre-wrap">{failure.detail}</code>
                                </li>
                            ))}
                        </ul>
                    </Section>

                    <Section title={t('admin.issues.drawer.parsed')}>
                        <KeyValues values={detail.parsed} skip={['items']} />
                    </Section>

                    <Section title={t('admin.issues.drawer.corrections')}>
                        {Object.keys(detail.corrections).length === 0 ? <p className="text-ink-2">{t('admin.issues.drawer.corrections_none')}</p> : <KeyValues values={detail.corrections} />}
                    </Section>

                    <Section title={t('admin.issues.drawer.payload')}>
                        <p className="mb-1.5 text-[12.5px] text-ink-2">{t('admin.issues.drawer.payload_hint')}</p>
                        <details className="rounded-md border border-rule">
                            <summary className="min-h-11 cursor-pointer px-3 py-2.5 font-medium">{t('admin.issues.drawer.payload_show')}</summary>
                            <div role="region" aria-label={t('common.ui.scroll_region', { label: t('admin.issues.drawer.payload') })} tabIndex={0} className="max-h-96 overflow-auto bg-paper">
                                <pre className="px-3 py-2 text-[12px] leading-snug">{JSON.stringify(detail.payload, null, 2)}</pre>
                            </div>
                        </details>
                    </Section>

                    <Section title={t('admin.issues.drawer.history')}>
                        {detail.history.length === 0 ? (
                            <p className="text-ink-2">{t('admin.issues.drawer.history_none')}</p>
                        ) : (
                            <ol className="flex flex-col gap-2">
                                {detail.history.map((entry) => {
                                    const event = EVENTS.includes(entry.event) ? t(`admin.issues.drawer.events.${entry.event}`) : entry.event;

                                    return (
                                        <li key={entry.id} className="rounded-md border border-rule px-3 py-2">
                                            <p>
                                                {entry.by === null
                                                    ? t('admin.issues.drawer.by_system', { event, time: when(entry.at) })
                                                    : t('admin.issues.drawer.by', { event, by: entry.by, time: when(entry.at) })}
                                            </p>
                                            {entry.reason !== null && <p className="text-ink-2">{t('admin.issues.drawer.reason', { reason: entry.reason })}</p>}
                                        </li>
                                    );
                                })}
                            </ol>
                        )}
                    </Section>
                </div>
            )}
        </Drawer>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section>
            <h3 className="mb-2 text-[14px]">{title}</h3>
            {children}
        </section>
    );
}

/** A flat object as terms and values; nested values as compact JSON. */
function KeyValues({ values, skip = [] }: { values: Record<string, unknown>; skip?: string[] }) {
    const entries = Object.entries(values).filter(([key]) => !skip.includes(key));

    return (
        <dl className="grid grid-cols-[minmax(8rem,auto)_1fr] gap-x-4 gap-y-1">
            {entries.map(([key, value]) => (
                <div key={key} className="contents">
                    <dt className="text-ink-2">
                        <code>{key}</code>
                    </dt>
                    <dd className="break-words">
                        <code>{value === null ? 'null' : typeof value === 'object' ? JSON.stringify(value) : String(value)}</code>
                    </dd>
                </div>
            ))}
        </dl>
    );
}
