import { useState } from 'react';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import ProgressPanel, { type ProgressStage } from '@/components/ProgressPanel';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { useApiAction } from '@/lib/useApiAction';
import type { PullRun } from '@/types/admin';

export type PullNowPanelProps = {
    /** The pull already running when the page loaded: followed at once. */
    runningRunId: number | null;
    /** A followed pull reached its end: the page reloads the status and the history. */
    onFinished: () => void;
};

const COUNTERS = ['fetched', 'parsed', 'accepted', 'flagged', 'quarantined', 'duplicates'] as const;

/**
 * "Pull now" (ARCHITECTURE.md §9.3, D-56): POST /api/odk/pull, then the run followed on
 * GET /api/odk/pull/{run} in a ProgressPanel (stage changes announced; the counters shown).
 * A pull already running, at load or when the button is pressed, is followed instead of
 * starting another. The button stays disabled while a pull runs.
 */
export default function PullNowPanel({ runningRunId, onFinished }: PullNowPanelProps) {
    const t = useTranslate();
    const action = useApiAction<{ run_id: number; already_running: boolean }>();
    const [runId, setRunId] = useState<number | null>(runningRunId);
    const [running, setRunning] = useState(runningRunId !== null);
    const [attached, setAttached] = useState(runningRunId !== null);

    const start = async () => {
        const result = await action.run('POST', '/api/odk/pull');
        if (result.ok) {
            setRunId(result.data.run_id);
            setRunning(true);
            setAttached(result.data.already_running);
        }
    };

    const stage = ({ data }: { data: PullRun }): ProgressStage => ({
        key: data.status,
        label: t(`admin.odk.pull.stages.${data.status}`),
        terminal: data.status !== 'running',
    });

    return (
        <Panel labelledBy="odk-pull-title">
            <PanelHeader id="odk-pull-title" title={t('admin.odk.pull.title')} description={t('admin.odk.pull.description')} />
            <div className="flex flex-col gap-3 px-5 pb-5">
                <div>
                    <button
                        type="button"
                        onClick={() => void start()}
                        disabled={action.pending || running}
                        className="min-h-11 rounded-md bg-deep px-4 text-[13.5px] font-medium text-panel disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {action.pending ? t('admin.odk.pull.starting') : t('admin.odk.pull.button')}
                    </button>
                </div>
                {action.error !== null && (
                    <p role="alert" className="rounded-md border border-bad bg-bad-bg px-3 py-2 text-[13px] text-bad">
                        {action.error}
                    </p>
                )}
                {attached && running && <p className="text-[13px] text-ink-2">{t('admin.odk.pull.attached')}</p>}
                {runId !== null && (
                    <ProgressPanel<{ data: PullRun }>
                        key={runId}
                        title={t('admin.odk.pull.progress')}
                        url={`/api/odk/pull/${runId}`}
                        stage={stage}
                        onDone={() => {
                            setRunning(false);
                            onFinished();
                        }}
                    >
                        {({ data }) => (
                            <dl aria-label={t('admin.odk.pull.counters')} className="grid grid-cols-2 gap-x-6 gap-y-1 sm:grid-cols-3">
                                {COUNTERS.map((counter) => (
                                    <div key={counter} className="flex justify-between gap-3">
                                        <dt>{t(`admin.odk.pull.${counter}`)}</dt>
                                        <dd className="font-medium text-ink tabular-nums">{formatCount(data[counter])}</dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                    </ProgressPanel>
                )}
            </div>
        </Panel>
    );
}
