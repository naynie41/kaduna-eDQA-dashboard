import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { GroupScores, OwnerSplit as OwnerSplitScores } from '@/types/dashboard';

export type OwnerSplitProps = {
    split: OwnerSplitScores;
    /** The heading id of the panel this sits in, for its own headings' level. */
    headingLevel?: 2 | 3;
    /** Monitor (D-75): all facilities in view, public and private together, shown first. */
    all?: { title: string; scores: GroupScores };
};

const DIMENSIONS = ['availability', 'consistency', 'validity'] as const;

/**
 * Public vs Private (Home §9.2.1, Monitor §9.2.4): each side's overall and its three dimensions;
 * on Monitor, all facilities first (D-75).
 */
export default function OwnerSplit({ split, headingLevel = 3, all }: OwnerSplitProps) {
    const t = useTranslate();

    return (
        <div className={`grid gap-px bg-rule ${all === undefined ? 'lg:grid-cols-2' : 'md:grid-cols-3'}`}>
            {all !== undefined && <Side owner="all" title={all.title} scores={all.scores} headingLevel={headingLevel} />}
            <Side owner="public" title={t('dashboard.home.public')} scores={split.public} headingLevel={headingLevel} />
            <Side owner="private" title={t('dashboard.home.private')} scores={split.private} headingLevel={headingLevel} />
        </div>
    );
}

type SideProps = { owner: 'all' | 'public' | 'private'; title: string; scores: GroupScores; headingLevel: 2 | 3 };

function Side({ owner, title, scores, headingLevel }: SideProps) {
    const t = useTranslate();
    const Heading = headingLevel === 2 ? 'h2' : 'h3';
    const colour = owner === 'all' ? 'text-ink' : owner === 'public' ? 'text-pub' : 'text-priv';

    return (
        <div className="bg-panel px-5 py-5">
            <div className="flex items-baseline gap-2.5">
                <Heading className={`text-sm font-semibold ${colour}`}>{title}</Heading>
                <span className="text-xs text-ink-2">{t('dashboard.home.visited', { count: formatCount(scores.facilities) })}</span>
            </div>
            <dl className="mt-2 flex flex-col-reverse">
                <dt className="text-2xs text-ink-2">{t('dashboard.home.overall_caption')}</dt>
                <dd className={`m-0 mt-1 font-display text-2xl leading-none font-bold tracking-[-0.03em] tabular-nums ${colour}`}>
                    {scores.overall.headline}
                    <span className="ml-2 align-middle font-sans text-xs font-medium text-ink-2">{scores.overall.bandLabel}</span>
                </dd>
            </dl>
            <dl className="mt-4 grid grid-cols-3 gap-px overflow-hidden rounded-md border border-rule bg-rule">
                {DIMENSIONS.map((dimension) => (
                    <div key={dimension} className="flex flex-col-reverse bg-panel px-3 py-2.5">
                        <dt className="text-2xs text-ink-2">{t(`enums.dimension.${dimension}`)}</dt>
                        <dd className="m-0 font-display text-lg font-semibold tabular-nums">{scores[dimension].headline}</dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}
