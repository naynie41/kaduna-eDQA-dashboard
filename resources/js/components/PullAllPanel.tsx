import { useState } from 'react';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import { useTranslate } from '@/lib/i18n';
import { useApiAction } from '@/lib/useApiAction';

export type PullAllRun = { source: string; run_id: number; already_running: boolean };

export type PullAllPanelProps = {
    /** Source key => name, to say which pulls started. */
    names: Record<string, string>;
    /** The runs started (or joined): each source's panel follows its own. */
    onStarted: (runs: PullAllRun[]) => void;
};

/**
 * "Pull all" (ARCHITECTURE.md §9.3): POST /api/sources/pull-all starts one pull per source that
 * is switched on (joining any already running), and each source's panel follows its run in its
 * own ProgressPanel. What started is said in a status line; a refusal (no source switched on) in
 * an alert.
 */
export default function PullAllPanel({ names, onStarted }: PullAllPanelProps) {
    const t = useTranslate();
    const action = useApiAction<{ runs: PullAllRun[] }>();
    const [started, setStarted] = useState<string | null>(null);

    const start = async () => {
        setStarted(null);
        const result = await action.run('POST', '/api/sources/pull-all');
        if (result.ok) {
            const list = result.data.runs.map((run) => {
                const name = names[run.source] ?? run.source;

                return run.already_running ? t('admin.sources.pull_all.joined', { source: name }) : name;
            });
            setStarted(t('admin.sources.pull_all.started', { sources: list.join(', ') }));
            onStarted(result.data.runs);
        }
    };

    return (
        <Panel labelledBy="sources-pull-all-title">
            <PanelHeader id="sources-pull-all-title" title={t('admin.sources.pull_all.title')} description={t('admin.sources.pull_all.description')} />
            <div className="flex flex-col gap-3 px-5 pb-5 text-sm">
                <div>
                    <button
                        type="button"
                        onClick={() => void start()}
                        disabled={action.pending}
                        className="btn btn-primary min-h-11"
                    >
                        {action.pending ? t('admin.sources.pull_all.starting') : t('admin.sources.pull_all.button')}
                    </button>
                </div>
                {action.error !== null && (
                    <p role="alert" className="notice notice-bad text-bad">
                        {action.error}
                    </p>
                )}
                <p role="status" className="text-sm">
                    {started}
                </p>
            </div>
        </Panel>
    );
}
