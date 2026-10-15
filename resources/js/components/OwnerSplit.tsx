import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { GroupScores, OwnerSplit as OwnerSplitScores } from '@/types/dashboard';

export type OwnerSplitProps = {
    split: OwnerSplitScores;
    /** The heading id of the panel this sits in, for its own headings' level. */
    headingLevel?: 2 | 3;
};

const DIMENSIONS = ['availability', 'consistency', 'validity'] as const;

/** Public vs Private (Home §9.2.1, Monitor §9.2.4): each side's overall and its three dimensions. */
export default function OwnerSplit({ split, headingLevel = 3 }: OwnerSplitProps) {
    return (
        <div className="grid gap-px bg-rule lg:grid-cols-2">
            <Side owner="public" scores={split.public} headingLevel={headingLevel} />
            <Side owner="private" scores={split.private} headingLevel={headingLevel} />
        </div>
    );
}

type SideProps = { owner: 'public' | 'private'; scores: GroupScores; headingLevel: 2 | 3 };

function Side({ owner, scores, headingLevel }: SideProps) {
    const t = useTranslate();
    const Heading = headingLevel === 2 ? 'h2' : 'h3';
    const colour = owner === 'public' ? 'text-pub' : 'text-priv';

    return (
        <div className="bg-panel px-4.5 py-4">
            <div className="flex items-baseline gap-2.5">
                <Heading className={`text-sm font-semibold ${colour}`}>{t(`dashboard.home.${owner}`)}</Heading>
                <span className="text-[12.5px] text-ink-2">{t('dashboard.home.visited', { count: formatCount(scores.facilities) })}</span>
            </div>
            <dl className="mt-2 flex flex-col-reverse">
                <dt className="text-[11.5px] text-ink-2">{t('dashboard.home.overall_caption')}</dt>
                <dd className={`m-0 font-display text-[38px] leading-none font-bold tabular-nums ${colour}`}>
                    {scores.overall.headline}
                    <span className="ml-2 align-middle font-sans text-[12px] font-medium text-ink-2">{scores.overall.bandLabel}</span>
                </dd>
            </dl>
            <dl className="mt-3.5 grid grid-cols-3 gap-px overflow-hidden rounded-[7px] border border-rule bg-rule">
                {DIMENSIONS.map((dimension) => (
                    <div key={dimension} className="flex flex-col-reverse bg-panel-soft px-2.5 py-2">
                        <dt className="text-[11px] text-ink-2">{t(`enums.dimension.${dimension}`)}</dt>
                        <dd className="m-0 font-display text-[17px] font-semibold tabular-nums">{scores[dimension].headline}</dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}
