import { useId, useState, type ReactNode } from 'react';
import { useTranslate } from '@/lib/i18n';

export type FigureProps = {
    title: string;
    description?: string;
    /** The chart, with its own role="img" and aria-label. */
    chart: ReactNode;
    /** The same numbers as an accessible table (CLAUDE.md hard rule 11). */
    table: ReactNode;
};

/**
 * Every chart sits in a figure with a caption and a "Show as table" switch, so its numbers can
 * be read without seeing the chart.
 */
export default function Figure({ title, description, chart, table }: FigureProps) {
    const t = useTranslate();
    const [asTable, setAsTable] = useState(false);
    const captionId = useId();
    const contentId = useId();

    return (
        <figure aria-labelledby={captionId} className="m-0">
            <figcaption className="flex flex-wrap items-start gap-x-4 gap-y-2 px-5 pt-4.5">
                <div className="min-w-56 flex-1">
                    <span id={captionId} className="block font-display text-md font-semibold tracking-[-0.015em]">
                        {title}
                    </span>
                    {description !== undefined && <span className="mt-1 block max-w-[72ch] text-sm text-ink-2">{description}</span>}
                </div>
                <button
                    type="button"
                    aria-controls={contentId}
                    onClick={() => setAsTable((current) => !current)}
                    className="btn btn-quiet btn-sm -mr-2"
                >
                    {asTable ? t('common.ui.show_chart') : t('common.ui.show_table')}
                </button>
            </figcaption>
            <div id={contentId} className="px-5 pt-1.5 pb-4">
                {asTable ? table : chart}
            </div>
        </figure>
    );
}
