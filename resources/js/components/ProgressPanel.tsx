import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useAnnounce } from '@/components/Announcer';
import { getJson } from '@/lib/api';
import { useTranslate } from '@/lib/i18n';

export type ProgressStage = {
    /** Compared between polls: a change of key is a change of stage. */
    key: string;
    /** Shown, and announced when the stage changes: "Fetching", "Done: 480 accepted". */
    label: string;
    /** Succeeded, failed, cancelled: polling stops. */
    terminal: boolean;
};

export type ProgressPanelProps<T> = {
    /** Names the panel and starts each announcement: "Pull", "Rescore Q2 2026". */
    title: string;
    /** The progress endpoint, polled with GET (GET /api/odk/pull/{run}, /api/rescores/{batch}). */
    url: string;
    stage: (data: T) => ProgressStage;
    /** The details under the stage (counters); shown on every poll, never announced. */
    children: (data: T) => ReactNode;
    /** Once, at the terminal state. */
    onDone?: (data: T) => void;
    /** Milliseconds between polls, growing; the last repeats. Reset to the first on a stage change. */
    intervals?: readonly number[];
};

export const DEFAULT_INTERVALS = [2000, 3000, 5000, 8000, 10000] as const;

/**
 * Queued work followed to its end (ARCHITECTURE.md §8: a pull, a rescore, an export). Polls the
 * endpoint with backoff, 2 s growing to 10 s, and stops at a terminal stage. Only a change of
 * stage is announced, through the page's one polite live region, so a screen reader hears
 * "Pull: Fetching" and "Pull: Done", not every tick of the counters. A failed poll is said
 * once, in the panel, and polling carries on.
 */
export default function ProgressPanel<T>({ title, url, stage, children, onDone, intervals = DEFAULT_INTERVALS }: ProgressPanelProps<T>) {
    const t = useTranslate();
    const announce = useAnnounce();
    const [data, setData] = useState<T | null>(null);
    const [failed, setFailed] = useState(false);
    const latest = useRef({ stage, onDone, announce, title, t });
    useEffect(() => {
        latest.current = { stage, onDone, announce, title, t };
    });

    useEffect(() => {
        const controller = new AbortController();
        let timer: ReturnType<typeof setTimeout> | null = null;
        let step = 0;
        let lastKey: string | null = null;
        let stopped = false;

        const poll = async () => {
            try {
                const next = await getJson<T>(url, controller.signal);
                if (stopped) {
                    return;
                }
                const current = latest.current.stage(next);
                setData(next);
                setFailed(false);
                if (current.key !== lastKey) {
                    lastKey = current.key;
                    step = 0;
                    latest.current.announce(latest.current.t('common.progress.stage', { label: latest.current.title, stage: current.label }));
                } else {
                    step += 1;
                }
                if (current.terminal) {
                    stopped = true;
                    latest.current.onDone?.(next);

                    return;
                }
            } catch {
                if (stopped || controller.signal.aborted) {
                    return;
                }
                setFailed(true);
                step += 1;
            }
            const wait = intervals[Math.min(step, intervals.length - 1)] ?? 10000;
            timer = setTimeout(() => void poll(), wait);
        };
        void poll();

        return () => {
            stopped = true;
            controller.abort();
            if (timer !== null) {
                clearTimeout(timer);
            }
        };
    }, [url, intervals]);

    const current = data === null ? null : stage(data);

    return (
        <section aria-label={title} className="rounded-md border border-rule bg-panel px-4 py-3">
            <p className="text-[14px] font-medium">{current === null ? t('common.loading') : current.label}</p>
            {data !== null && <div className="mt-1.5 text-[13px] text-ink-2">{children(data)}</div>}
            {failed && <p className="mt-1.5 text-[12.5px] text-bad">{t('common.progress.failed')}</p>}
        </section>
    );
}
