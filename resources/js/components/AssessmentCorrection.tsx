import { useId, useState } from 'react';
import { useAnnounce } from '@/components/Announcer';
import ConfirmWithReasonDialog from '@/components/ConfirmWithReasonDialog';
import { correctionBody, draftCorrection, offsetOf, toLocalInput, type CorrectionDraft } from '@/lib/corrections';
import { formatTime } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { useApiAction } from '@/lib/useApiAction';
import type { FacilityDetail, ItemResult } from '@/types/dashboard';

const RESULTS: ItemResult[] = ['pass', 'fail', 'na'];

export type Correction = {
    editing: boolean;
    begin: () => void;
    cancel: () => void;
    results: Record<string, ItemResult>;
    setResult: (key: string, result: ItemResult) => void;
    start: string;
    setStart: (value: string) => void;
    end: string;
    setEnd: (value: string) => void;
    draft: CorrectionDraft;
};

/** Correction mode's state: the edited results and times, and their difference from the drawer's (D-58). */
export function useCorrection(detail: FacilityDetail): Correction {
    const initialResults = () =>
        Object.fromEntries(detail.items.flatMap((group) => group.items.flatMap((item) => (item.result === null ? [] : [[item.key, item.result] as const]))));
    const [editing, setEditing] = useState(false);
    const [results, setResults] = useState<Record<string, ItemResult>>(initialResults);
    const [start, setStart] = useState(detail.assessment === null ? '' : toLocalInput(detail.assessment.started_at));
    const [end, setEnd] = useState(detail.assessment === null ? '' : toLocalInput(detail.assessment.ended_at));

    const reset = () => {
        setResults(initialResults());
        setStart(detail.assessment === null ? '' : toLocalInput(detail.assessment.started_at));
        setEnd(detail.assessment === null ? '' : toLocalInput(detail.assessment.ended_at));
    };

    return {
        editing,
        begin: () => {
            reset();
            setEditing(true);
        },
        cancel: () => {
            reset();
            setEditing(false);
        },
        results,
        setResult: (key, result) => setResults((previous) => ({ ...previous, [key]: result })),
        start,
        setStart,
        end,
        setEnd,
        draft: draftCorrection(detail, results, start, end),
    };
}

/** One item's result as a select while correcting: the label names the item and its month. */
export function ItemResultSelect({ id, label, value, onChange }: { id: string; label: string; value: ItemResult; onChange: (result: ItemResult) => void }) {
    const t = useTranslate();

    return (
        <>
            <label htmlFor={id} className="sr-only">
                {label}
            </label>
            <select id={id} value={value} onChange={(event) => onChange(event.target.value as ItemResult)} className="min-h-9 shrink-0 rounded-md border border-rule bg-panel px-2 text-[12.5px]">
                {RESULTS.map((result) => (
                    <option key={result} value={result}>
                        {t(`dashboard.drawer.results.${result}`)}
                    </option>
                ))}
            </select>
        </>
    );
}

export type CorrectionPanelProps = {
    detail: FacilityDetail;
    correction: Correction;
    /** After a saved correction: the drawer reloads the facility for the new scores and audit trail. */
    onSaved: () => void;
};

/**
 * "Correct this assessment" (ARCHITECTURE.md §9.2.7, D-58), shown while the round is open: the
 * button, then the visit times, the summary of what would change ("2 items changed:
 * avail_m2_… pass → fail"), and Review and save, which asks for the reason
 * (ConfirmWithReasonDialog). The server recomputes the scores; nothing here sends a score.
 */
export default function CorrectionPanel({ detail, correction, onSaved }: CorrectionPanelProps) {
    const t = useTranslate();
    const announce = useAnnounce();
    const api = useApiAction<{ data: unknown }>();
    const ids = { title: useId(), start: useId(), end: useId(), hint: useId(), summary: useId() };
    const [reviewing, setReviewing] = useState(false);
    const { assessment, round } = detail;

    if (assessment === null || round === null) {
        return null;
    }
    if (!round.open) {
        return <p className="mt-3 text-[12.5px] text-ink-2">{t('dashboard.drawer.correct.closed', { round: round.label })}</p>;
    }
    if (!correction.editing) {
        return (
            <button type="button" onClick={correction.begin} className="mt-3 min-h-11 rounded-md border border-rule bg-panel px-3 text-[13px] font-medium hover:bg-paper">
                {t('dashboard.drawer.correct.start')}
            </button>
        );
    }

    const { draft } = correction;
    const label = (key: string, old: string, next: string) => t('dashboard.drawer.correct.change', { key, old: t(`dashboard.drawer.results.${old}`), new: t(`dashboard.drawer.results.${next}`) });
    const list = draft.changes.map((change) => label(change.key, change.old, change.new)).join('; ');
    const lines = [
        ...(draft.changes.length === 1 ? [t('dashboard.drawer.correct.summary_item', { list })] : []),
        ...(draft.changes.length > 1 ? [t('dashboard.drawer.correct.summary_items', { count: String(draft.changes.length), list })] : []),
        ...(draft.startedAt !== null || draft.endedAt !== null
            ? [
                  t('dashboard.drawer.correct.summary_times', {
                      old_start: formatTime(assessment.started_at),
                      old_end: formatTime(assessment.ended_at),
                      new_start: formatTime(draft.startedAt ?? assessment.started_at),
                      new_end: formatTime(draft.endedAt ?? assessment.ended_at),
                  }),
              ]
            : []),
    ];
    const nothing = lines.length === 0;

    return (
        <section aria-labelledby={ids.title} className="mt-3 flex flex-col gap-3 rounded-md border border-deep bg-wash p-3 text-[13px]">
            <h4 id={ids.title} className="text-[14px] font-semibold">
                {t('dashboard.drawer.correct.title')}
            </h4>
            <p className="text-ink">{t('dashboard.drawer.correct.intro')}</p>
            <fieldset className="flex flex-wrap gap-3">
                <legend className="mb-1 font-medium">{t('dashboard.drawer.correct.times')}</legend>
                <p id={ids.hint} className="w-full text-[12.5px] text-ink">
                    {t('dashboard.drawer.correct.times_hint', { offset: offsetOf(assessment.started_at) })}
                </p>
                <div className="flex flex-col gap-1">
                    <label htmlFor={ids.start}>{t('dashboard.drawer.correct.started_at')}</label>
                    <input id={ids.start} type="datetime-local" aria-describedby={ids.hint} value={correction.start} onChange={(event) => correction.setStart(event.target.value)} className="min-h-11 rounded-md border border-rule bg-panel px-2" />
                </div>
                <div className="flex flex-col gap-1">
                    <label htmlFor={ids.end}>{t('dashboard.drawer.correct.ended_at')}</label>
                    <input id={ids.end} type="datetime-local" aria-describedby={ids.hint} value={correction.end} onChange={(event) => correction.setEnd(event.target.value)} className="min-h-11 rounded-md border border-rule bg-panel px-2" />
                </div>
            </fieldset>
            <div id={ids.summary} role="status" className="rounded-md bg-panel px-3 py-2">
                {nothing ? (
                    <p>{t('dashboard.drawer.correct.summary_none')}</p>
                ) : (
                    lines.map((line) => (
                        <p key={line} className="break-words">
                            {line}
                        </p>
                    ))
                )}
            </div>
            <div className="flex flex-wrap gap-2">
                <button type="button" disabled={nothing} aria-describedby={ids.summary} onClick={() => setReviewing(true)} className="min-h-11 rounded-md bg-deep px-4 font-medium text-panel disabled:cursor-not-allowed disabled:opacity-50">
                    {t('dashboard.drawer.correct.review')}
                </button>
                <button type="button" onClick={correction.cancel} className="min-h-11 rounded-md border border-rule bg-panel px-4">
                    {t('dashboard.drawer.correct.cancel')}
                </button>
            </div>
            <ConfirmWithReasonDialog
                open={reviewing}
                title={t('dashboard.drawer.correct.dialog_title')}
                consequence={t('dashboard.drawer.correct.dialog_consequence', { summary: lines.join(' ') })}
                confirmLabel={t('dashboard.drawer.correct.dialog_confirm')}
                onClose={() => setReviewing(false)}
                onConfirm={async (reason) => {
                    const result = await api.run('POST', `/api/assessments/${assessment.id}/corrections`, correctionBody(draft, reason));
                    if (!result.ok) {
                        return { ok: false, message: result.message, fieldError: result.fieldErrors.reason ?? null };
                    }
                    announce(t('dashboard.drawer.correct.saved'));
                    correction.cancel();
                    onSaved();

                    return { ok: true };
                }}
            />
        </section>
    );
}
