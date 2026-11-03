import DimensionBar from '@/components/DimensionBar';
import StatusLabel from '@/components/StatusLabel';
import { formatCount, formatThreshold } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { DimensionScores, StateSummary } from '@/types/dashboard';

export type HomeHeroProps = {
    hero: StateSummary;
    dimensions: DimensionScores;
    /** The month in view ("June 2026") when the Duration is one month (D-75), else null. */
    month?: string | null;
};

const DIMENSIONS = ['availability', 'consistency', 'validity'] as const;

/**
 * Home's hero (ARCHITECTURE.md §9.2.1). Left: the average quality score and the four counts, each
 * a description list (term then value: the average is labelled above, each count below its number). Right: the three
 * dimensions and the overall as bars with the Strong mark, and a note naming the weakest. The
 * thresholds come from the rule version, never written in.
 */
export default function HomeHero({ hero, dimensions, month = null }: HomeHeroProps) {
    const t = useTranslate();
    const hasData = hero.facilitiesVisited > 0;
    // A threshold missing from the props (no rule version) reads as a dash, never NaN.
    const strong = typeof hero.strongAt === 'number' ? formatThreshold(hero.strongAt) : '—';
    const weak = typeof hero.weakBelow === 'number' ? formatThreshold(hero.weakBelow) : '—';
    const minis: [string, string][] = [
        [t('dashboard.home.facilities'), formatCount(hero.facilitiesVisited)],
        [t('dashboard.home.lgas'), formatCount(hero.lgasCovered)],
        [t('dashboard.home.strong', { threshold: strong }), formatCount(hero.facilitiesStrong)],
        [t('dashboard.home.weak', { threshold: weak }), formatCount(hero.facilitiesWeak)],
    ];
    const weakest = dimensions.weakest;

    return (
        <div className="overflow-hidden rounded-panel border border-rule bg-panel lg:grid lg:grid-cols-[300px_minmax(0,1fr)]">
            <div className="flex flex-col border-b border-rule bg-panel p-6 lg:border-r lg:border-b-0">
                <dl>
                    <dt className="text-sm font-medium text-ink-2">{t('dashboard.home.average_label')}</dt>
                    <dd className="m-0 mt-3 font-display text-display font-bold tracking-[-0.04em] text-ink tabular-nums">
                        {hero.overall.headline}
                        {' '}
                        <span className="ml-1 inline-block align-[0.55em] font-sans text-base tracking-normal">
                            <StatusLabel band={hero.overall.band} label={hero.overall.bandLabel} />
                        </span>
                    </dd>
                </dl>
                <p className="mt-3 mb-6 max-w-[32ch] text-sm leading-snug text-ink-2">
                    {hasData
                        ? month === null
                            ? t('dashboard.home.averaged_across', { count: formatCount(hero.facilitiesVisited) })
                            : t('dashboard.home.averaged_across_month', { count: formatCount(hero.facilitiesVisited), month })
                        : t('dashboard.home.no_assessments')}
                </p>
                <dl className="-mx-6 mt-auto -mb-6 grid grid-cols-2 gap-px border-t border-rule bg-rule">
                    {minis.map(([label, value]) => (
                        <div key={label} className="flex flex-col-reverse bg-panel px-4 py-3.5">
                            <dt className="mt-px text-2xs leading-tight text-ink-2">{label}</dt>
                            <dd className="m-0 font-display text-xl font-semibold tabular-nums">{value}</dd>
                        </div>
                    ))}
                </dl>
            </div>
            <section aria-labelledby="home-dimensions" className="min-w-0 px-6 pt-5 pb-6">
                <h2 id="home-dimensions" className="text-md tracking-[-0.015em]">
                    {t('dashboard.home.dimensions_title')}
                </h2>
                <p className="mt-1 max-w-[64ch] text-sm text-ink-2">{month === null ? t('dashboard.home.dimensions_intro') : t('dashboard.home.dimensions_intro_month', { month })}</p>
                <ul className="mt-5 flex flex-col gap-4">
                    {DIMENSIONS.map((dimension) => (
                        <DimensionBar
                            key={dimension}
                            label={t(`enums.dimension.${dimension}`)}
                            help={t(`dashboard.home.dimension_help.${dimension}`)}
                            score={dimensions[dimension]}
                            strongAt={hero.strongAt}
                        />
                    ))}
                    <DimensionBar label={t('dashboard.home.overall')} help={t('dashboard.home.dimension_help.overall')} score={dimensions.overall} strongAt={hero.strongAt} />
                </ul>
                {typeof hero.strongAt === 'number' && <p className="mt-2.5 text-xs text-ink-2">{t('dashboard.home.marker_legend', { threshold: strong })}</p>}
                {weakest !== null && hasData && (
                    <p className="mt-1 text-xs text-ink-2">
                        {t('dashboard.home.weakest_note', { dimension: t(`enums.dimension.${weakest}`), score: dimensions[weakest].display })}
                    </p>
                )}
            </section>
        </div>
    );
}
