import type { ReactNode } from 'react';
import HistorySparkline from '@/components/HistorySparkline';
import ScoreTag from '@/components/ScoreTag';
import { formatDate, formatDateTime } from '@/lib/format';
import { useTranslate, useTranslationList, type Translate } from '@/lib/i18n';
import type { Dimension, FacilityDetail, ItemResult } from '@/types/dashboard';

export type FacilityDetailViewProps = {
    detail: FacilityDetail;
};

const DIMENSIONS: Dimension[] = ['availability', 'consistency', 'validity'];
const EVENTS = ['created', 'updated', 'deleted', 'flag_overridden'];

/** The facility drawer's body (ARCHITECTURE.md §9.2.7). Renders the server's values as they are. */
export default function FacilityDetailView({ detail }: FacilityDetailViewProps) {
    const t = useTranslate();
    const monthsShort = useTranslationList('common.months_short');
    const date = (value: string) => (monthsShort.length === 12 ? formatDate(value, monthsShort) : value);
    const dateTime = (value: string) => (monthsShort.length === 12 ? formatDateTime(value, monthsShort) : value);
    const { facility, round, assessment, scores } = detail;

    return (
        <div className="flex flex-col gap-6">
            <Section title={t('dashboard.drawer.scores_title', { round: round?.label ?? '' })}>
                <p className="mb-3 text-[13px] text-ink-2">
                    {assessment !== null
                        ? t('dashboard.drawer.assessed', { date: date(assessment.assessed_on), assessor: assessment.assessor })
                        : round !== null
                          ? t('dashboard.drawer.not_assessed', { round: round.label })
                          : t('dashboard.drawer.never_assessed')}
                </p>
                <dl className="grid grid-cols-2 gap-2 rail:grid-cols-4">
                    {[...DIMENSIONS, 'overall' as const].map((key) => (
                        <div key={key} className="rounded-md border border-rule bg-panel px-3 py-2">
                            <dt className="text-[12px] text-ink-2">{key === 'overall' ? t('dashboard.drawer.overall') : t(`enums.dimension.${key}`)}</dt>
                            <dd className="mt-1">
                                <ScoreTag score={scores[key]} />
                            </dd>
                        </div>
                    ))}
                </dl>
            </Section>

            <Section title={t('dashboard.drawer.items_title')}>
                {detail.items.length === 0 ? (
                    <p className="text-[13px] text-ink-2">{t('dashboard.drawer.items_none')}</p>
                ) : (
                    <div className="flex flex-col gap-2">
                        {DIMENSIONS.map((dimension) => {
                            const groups = detail.items.filter((group) => group.dimension === dimension);
                            if (groups.length === 0) {
                                return null;
                            }

                            return (
                                <details key={dimension} className="rounded-md border border-rule bg-panel" open={dimension === 'availability'}>
                                    <summary className="flex min-h-11 cursor-pointer items-center gap-3 px-3 py-2 text-[14px] font-medium">
                                        <span className="flex-1">{t(`enums.dimension.${dimension}`)}</span>
                                        <ScoreTag score={scores[dimension]} />
                                    </summary>
                                    <div className="grid gap-3 border-t border-rule px-3 py-3 rail:grid-cols-3">
                                        {groups.map((group) => (
                                            <div key={group.slot}>
                                                <h4 className="mb-1.5 flex flex-wrap items-center justify-between gap-2 text-[13px] font-semibold">
                                                    {group.month}
                                                    <ScoreTag score={group.score} />
                                                </h4>
                                                <ul className="flex flex-col">
                                                    {group.items.map((item) => (
                                                        <li key={item.code} className="flex items-center justify-between gap-2 border-b border-paper py-1 text-[12.5px] last:border-b-0">
                                                            <span className="min-w-0 break-words">{item.label ?? item.code}</span>
                                                            <Result result={item.result} t={t} />
                                                        </li>
                                                    ))}
                                                </ul>
                                            </div>
                                        ))}
                                    </div>
                                </details>
                            );
                        })}
                    </div>
                )}
            </Section>

            <Section title={t('dashboard.drawer.history_title')}>
                <HistorySparkline history={detail.history} />
            </Section>

            <Section title={t('dashboard.drawer.flags_title')}>
                {detail.flags.length === 0 ? (
                    <p className="text-[13px] text-ink-2">{t('dashboard.drawer.flags_none')}</p>
                ) : (
                    <ul className="flex flex-col gap-2">
                        {detail.flags.map((flag) => (
                            <li key={flag.code} className="rounded-md border border-rule bg-panel px-3 py-2 text-[13px]">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">{flag.label}</span>
                                    <span className={`rounded-full px-2 py-0.5 text-[11.5px] font-semibold ${flag.overridden === null ? 'bg-warn-bg text-warn' : 'bg-wash text-ink-2'}`}>
                                        {flag.overridden === null ? t('dashboard.drawer.flag_open') : t('dashboard.drawer.flag_overridden')}
                                    </span>
                                </div>
                                {flag.overridden !== null && (
                                    <p className="mt-1 text-ink-2">
                                        {t('dashboard.drawer.overridden_by', { by: flag.overridden.by ?? t('dashboard.drawer.someone'), date: dateTime(flag.overridden.at) })}
                                        {flag.overridden.reason !== null && <> {t('dashboard.drawer.reason', { reason: flag.overridden.reason })}</>}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            <Section title={t('dashboard.drawer.attachments_title')}>
                {detail.attachments.length === 0 ? (
                    <p className="text-[13px] text-ink-2">{t('dashboard.drawer.attachments_none')}</p>
                ) : (
                    <ul className="flex flex-wrap gap-3 text-[13px]">
                        {detail.attachments.map((attachment) => (
                            <li key={attachment.id} className="min-w-0 max-w-full">
                                <Attachment attachment={attachment} facility={facility.name} round={round?.label ?? ''} t={t} />
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            <Section title={t('dashboard.drawer.activity_title')}>
                {detail.activity.length === 0 ? (
                    <p className="text-[13px] text-ink-2">{t('dashboard.drawer.activity_none')}</p>
                ) : (
                    <ol className="flex flex-col gap-2">
                        {detail.activity.map((entry) => (
                            <li key={entry.id} className="rounded-md border border-rule bg-panel px-3 py-2 text-[13px]">
                                <p>
                                    <span className="font-medium">{EVENTS.includes(entry.event) ? t(`dashboard.drawer.events.${entry.event}`) : entry.event}</span>
                                    {' · '}
                                    {entry.subject === 'item' && entry.item_code !== null ? t('dashboard.drawer.on_item', { code: entry.item_code }) : t('dashboard.drawer.on_assessment')}
                                </p>
                                <p className="text-ink-2">
                                    <time dateTime={entry.at}>{dateTime(entry.at)}</time>, {entry.actor ?? t('dashboard.drawer.system')}
                                </p>
                                {entry.changes.length > 0 && (
                                    <ul className="mt-1">
                                        {entry.changes.map((change) => (
                                            <li key={change.field} className="break-words">
                                                {t('dashboard.drawer.change', {
                                                    field: change.field,
                                                    old: change.old ?? t('dashboard.drawer.empty_value'),
                                                    new: change.new ?? t('dashboard.drawer.empty_value'),
                                                })}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {entry.reason !== null && <p className="mt-1">{t('dashboard.drawer.reason', { reason: entry.reason })}</p>}
                            </li>
                        ))}
                    </ol>
                )}
            </Section>

            <Section title={t('dashboard.drawer.details')}>
                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-[13px]">
                    <Term label={t('dashboard.drawer.code')}>{facility.code}</Term>
                    <Term label={t('dashboard.drawer.level')}>{facility.level_label}</Term>
                    <Term label={t('dashboard.drawer.owner')}>{facility.ownership_label}</Term>
                    <Term label={t('dashboard.drawer.status')}>{facility.active ? t('dashboard.drawer.active') : t('dashboard.drawer.inactive')}</Term>
                </dl>
            </Section>

            <Section title={t('dashboard.drawer.odk_title')}>
                {detail.odk.instance_id === null ? (
                    <p className="text-[13px] text-ink-2">{t('dashboard.drawer.odk_none')}</p>
                ) : (
                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-[13px]">
                        <Term label={t('dashboard.drawer.instance')}>
                            <code className="break-all">{detail.odk.instance_id}</code>
                        </Term>
                    </dl>
                )}
                {detail.odk.instance_id !== null &&
                    (detail.odk.url !== null ? (
                        <a href={detail.odk.url} target="_blank" rel="noopener noreferrer" className="mt-2 inline-block text-[13px] font-medium text-deep underline">
                            {t('dashboard.drawer.open_in_odk')} <span className="sr-only">{t('dashboard.drawer.new_tab')}</span>
                        </a>
                    ) : (
                        <p className="mt-2 text-[13px] text-ink-2">{t('dashboard.drawer.odk_not_configured')}</p>
                    ))}
            </Section>
        </div>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section>
            <h3 className="mb-2 text-[14.5px]">{title}</h3>
            {children}
        </section>
    );
}

function Term({ label, children }: { label: string; children: ReactNode }) {
    return (
        <>
            <dt className="text-ink-2">{label}</dt>
            <dd>{children}</dd>
        </>
    );
}

const RESULT_TONE: Record<ItemResult, string> = { pass: 'text-ok', fail: 'text-bad', na: 'text-ink-2' };

/** Pass / Fail / N/A as an icon and a word: the word carries the meaning, never the colour. */
function Result({ result, t }: { result: ItemResult | null; t: Translate }) {
    if (result === null) {
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

const REASONS = ['unsafe_name', 'too_large', 'type_not_allowed'];

/**
 * One ODK attachment. A fetched image is a thumbnail (alt text: the file, facility and round)
 * linking to the full size; any other fetched file is a download link; one not fetched says why
 * in words, never by colour alone.
 */
function Attachment({ attachment, facility, round, t }: { attachment: FacilityDetail['attachments'][number]; facility: string; round: string; t: Translate }) {
    const { filename, url, mime, status, reason } = attachment;

    if (url !== null && mime !== null && mime.startsWith('image/')) {
        return (
            <figure className="flex w-32 flex-col gap-1">
                <a href={url} target="_blank" rel="noreferrer" className="block rounded-md focus-visible:outline-2 focus-visible:outline-offset-2" aria-label={t('dashboard.drawer.attachment_open', { filename })}>
                    <img src={url} alt={t('dashboard.drawer.attachment_alt', { filename, facility, round })} loading="lazy" className="h-24 w-32 rounded-md border border-rule object-cover" />
                </a>
                <figcaption className="break-words text-[12px] text-ink-2">{filename}</figcaption>
            </figure>
        );
    }

    if (url !== null) {
        return (
            <a href={url} download={filename} className="inline-flex min-h-11 items-center break-all underline">
                {t('dashboard.drawer.attachment_download', { filename })}
            </a>
        );
    }

    return (
        <p className="break-words">
            {filename}
            <span className="text-ink-2">
                {' · '}
                {status === 'fetched' ? '' : t(`dashboard.drawer.attachment_status.${status}`)}
                {status === 'rejected' && reason !== null && REASONS.includes(reason) ? `: ${t(`dashboard.drawer.attachment_reasons.${reason}`)}` : ''}
            </span>
        </p>
    );
}
