import { useState } from 'react';
import ProgressPanel, { type ProgressStage } from '@/components/ProgressPanel';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { useApiAction } from '@/lib/useApiAction';
import type { PullRun } from '@/types/admin';

export type RunCounter = 'fetched' | 'parsed' | 'accepted' | 'flagged' | 'quarantined' | 'duplicates' | 'cross_source_duplicates' | 'deletions_detected';

export type SourceRunControlProps = {
    /** POST /api/sources/{key}/pull or /reconcile: answers { run_id, already_running }. */
    url: string;
    /** The run followed: running at load, started here, or started by Pull all. */
    runId: number | null;
    /** The run was already running when it was asked for: this follows it. */
    attached: boolean;
    counters: readonly RunCounter[];
    /** `source` is read after the button text by screen readers only: "Pull now ODK Central". */
    labels: { button: string; source: string; starting: string; attached: string; progress: string };
    onStarted: (runId: number, alreadyRunning: boolean) => void;
    /** A followed run reached its end: the page reloads the status and the history. */
    onFinished: () => void;
};

/**
 * One source action (ARCHITECTURE.md §9.3): Pull now or Reconcile. The button posts, then the run
 * is followed on GET /api/pulls/{run} in a ProgressPanel: stage changes are announced, the
 * counters are shown. The page owns which run is followed, so a run started by Pull all is
 * followed here too. The button stays disabled while the run is going.
 */
export default function SourceRunControl({ url, runId, attached, counters, labels, onStarted, onFinished }: SourceRunControlProps) {
    const t = useTranslate();
    const action = useApiAction<{ run_id: number; already_running: boolean }>();
    const [finished, setFinished] = useState<number | null>(null);
    const running = runId !== null && finished !== runId;

    const start = async () => {
        const result = await action.run('POST', url);
        if (result.ok) {
            onStarted(result.data.run_id, result.data.already_running);
        }
    };

    const stage = ({ data }: { data: PullRun }): ProgressStage => ({
        key: data.status,
        label: t(`admin.sources.pull.stages.${data.status}`),
        terminal: data.status !== 'running',
    });

    return (
        <div className="flex flex-col gap-3">
            <div>
                <button
                    type="button"
                    onClick={() => void start()}
                    disabled={action.pending || running}
                    className="min-h-11 rounded-md bg-deep px-4 text-[13.5px] font-medium text-panel disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {action.pending ? labels.starting : labels.button} <span className="sr-only">{labels.source}</span>
                </button>
            </div>
            {action.error !== null && (
                <p role="alert" className="rounded-md border border-bad bg-bad-bg px-3 py-2 text-[13px] text-bad">
                    {action.error}
                </p>
            )}
            {attached && running && <p className="text-[13px] text-ink-2">{labels.attached}</p>}
            {runId !== null && (
                <ProgressPanel<{ data: PullRun }>
                    key={runId}
                    title={labels.progress}
                    url={`/api/pulls/${runId}`}
                    stage={stage}
                    onDone={() => {
                        setFinished(runId);
                        onFinished();
                    }}
                >
                    {({ data }) => (
                        <dl aria-label={t('admin.sources.pull.counters')} className="grid grid-cols-1 gap-x-6 gap-y-1 min-[420px]:grid-cols-2">
                            {counters.map((counter) => (
                                <div key={counter} className="flex justify-between gap-3">
                                    <dt>{t(`admin.sources.pull.${counter}`)}</dt>
                                    <dd className="font-medium text-ink tabular-nums">{formatCount(data[counter])}</dd>
                                </div>
                            ))}
                        </dl>
                    )}
                </ProgressPanel>
            )}
        </div>
    );
}
