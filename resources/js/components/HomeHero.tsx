import DimensionBar from '@/components/DimensionBar';
import { formatCount, formatThreshold } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { DimensionScores, StateSummary } from '@/types/dashboard';

export type HomeHeroProps = {
    hero: StateSummary;
    dimensions: DimensionScores;
};

const DIMENSIONS = ['availability', 'consistency', 'validity'] as const;

/**
 * Home's hero (ARCHITECTURE.md §9.2.1). Left: the average quality score and the four counts, each
 * a description list (term then value: the average is labelled above, each count below its number). Right: the three
 * dimensions and the overall as bars with the Strong mark, and a note naming the weakest. The
 * thresholds come from the rule version, never written in.
 */
export default function HomeHero({ hero, dimensions }: HomeHeroProps) {
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
        <div className="overflow-hidden rounded-panel border border-rule bg-panel shadow-panel lg:grid lg:grid-cols-[270px_minmax(0,1fr)]">
            <div className="flex flex-col border-b border-rule bg-gradient-to-b from-panel-soft to-panel-tint p-5.5 lg:border-r lg:border-b-0">
                <dl>
                    <dt className="text-[12.5px] text-ink-2">{t('dashboard.home.average_label')}</dt>
                    <dd className="m-0 mt-1.5 font-display text-[52px] leading-[0.94] font-bold tracking-[-0.03em] tabular-nums lg:text-[64px]">
                        {hero.overall.headline}
                        <span className="sr-only"> {hero.overall.bandLabel}</span>
                    </dd>
                </dl>
                <p className="mt-2.5 mb-5 max-w-[31ch] text-[13px] leading-snug text-ink-2">
                    {hasData ? t('dashboard.home.averaged_across', { count: formatCount(hero.facilitiesVisited) }) : t('dashboard.home.no_assessments')}
                </p>
                <dl className="-mx-5.5 mt-auto -mb-5.5 grid grid-cols-2 gap-px border-t border-rule bg-rule">
                    {minis.map(([label, value]) => (
                        <div key={label} className="flex flex-col-reverse bg-panel-soft px-3.5 py-3">
                            <dt className="mt-px text-[11.5px] leading-tight text-ink-2">{label}</dt>
                            <dd className="m-0 font-display text-[19px] font-semibold tabular-nums">{value}</dd>
                        </div>
                    ))}
                </dl>
            </div>
            <section aria-labelledby="home-dimensions" className="min-w-0 px-5.5 pt-4.5 pb-5">
                <h2 id="home-dimensions" className="text-[15.5px]">
                    {t('dashboard.home.dimensions_title')}
                </h2>
                <p className="mt-0.5 max-w-[62ch] text-[12.5px] text-ink-2">{t('dashboard.home.dimensions_intro')}</p>
                <ul className="mt-3.5 flex flex-col gap-3">
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
                {typeof hero.strongAt === 'number' && <p className="mt-2.5 text-[12.5px] text-ink-2">{t('dashboard.home.marker_legend', { threshold: strong })}</p>}
                {weakest !== null && hasData && (
                    <p className="mt-1 text-[12.5px] text-ink-2">
                        {t('dashboard.home.weakest_note', { dimension: t(`enums.dimension.${weakest}`), score: dimensions[weakest].display })}
                    </p>
                )}
            </section>
        </div>
    );
}
