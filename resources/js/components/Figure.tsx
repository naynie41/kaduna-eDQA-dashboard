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
            <figcaption className="flex flex-wrap items-start gap-3 px-5 pt-4">
                <div className="min-w-56 flex-1">
                    <span id={captionId} className="block font-display text-[15.5px] font-semibold">
                        {title}
                    </span>
                    {description !== undefined && <span className="mt-0.5 block max-w-[70ch] text-[12.5px] text-ink-2">{description}</span>}
                </div>
                <button
                    type="button"
                    aria-controls={contentId}
                    onClick={() => setAsTable((current) => !current)}
                    className="rounded-md px-2.5 py-1 text-[12.5px] text-deep-2 hover:bg-paper"
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
