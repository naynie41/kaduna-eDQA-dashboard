import { useState, type ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { useAnnounce } from '@/components/Announcer';
import DataTable, { type DataTableColumn, type DataTableSort } from '@/components/DataTable';
import EmptyState from '@/components/EmptyState';
import Figure from '@/components/Figure';
import HeatCell from '@/components/HeatCell';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import ScoreTag from '@/components/ScoreTag';
import SegmentedControl from '@/components/SegmentedControl';
import Skeleton from '@/components/Skeleton';
import StatusLabel from '@/components/StatusLabel';
import Tabs from '@/components/Tabs';
import VisuallyHidden from '@/components/VisuallyHidden';
import { useTranslate, type Translate } from '@/lib/i18n';
import type { Band, ScoreValue } from '@/types/score';

/*
 * The local-only component gallery (DevComponentsController). Every value is a hand-written
 * sample in the server's ScoreValue shape: this page, like every page, does no score arithmetic.
 */

type Sample = { value: number | null; display: string; band: Band | null };

const BAND_SAMPLES: Sample[] = [
    { value: 93.1, display: '93.1', band: 'strong' },
    { value: 85.6, display: '85.6', band: 'acceptable' },
    { value: 77.4, display: '77.4', band: 'review' },
    { value: 58.6, display: '58.6', band: 'needs_action' },
    { value: null, display: '—', band: null },
];

const HEAT_SAMPLES: Sample[] = [
    { value: 40, display: '40.0', band: 'needs_action' },
    { value: 55, display: '55.0', band: 'needs_action' },
    { value: 70, display: '70.0', band: 'review' },
    { value: 85, display: '85.0', band: 'acceptable' },
    { value: 100, display: '100.0', band: 'strong' },
    { value: null, display: '—', band: null },
];

const LGA_ROWS: { lga: string; facilities: string; months: Sample[]; overall: Sample }[] = [
    { lga: 'Birnin Gwari', facilities: '11', months: [HEAT_SAMPLES[2], HEAT_SAMPLES[1], HEAT_SAMPLES[2]].filter(isSample), overall: { value: 77.4, display: '77.4', band: 'review' } },
    { lga: 'Kaduna South', facilities: '21', months: [HEAT_SAMPLES[4], HEAT_SAMPLES[3], HEAT_SAMPLES[4]].filter(isSample), overall: { value: 93.2, display: '93.2', band: 'strong' } },
    { lga: 'Zaria', facilities: '25', months: [HEAT_SAMPLES[3], HEAT_SAMPLES[3], HEAT_SAMPLES[5]].filter(isSample), overall: { value: 83.2, display: '83.2', band: 'acceptable' } },
];

const TREND: { round: string; overall: Sample }[] = [
    { round: 'Q3 2025', overall: { value: 85.3, display: '85.3', band: 'acceptable' } },
    { round: 'Q4 2025', overall: { value: 86.0, display: '86.0', band: 'acceptable' } },
    { round: 'Q1 2026', overall: { value: 85.7, display: '85.7', band: 'acceptable' } },
    { round: 'Q2 2026', overall: { value: 86.0, display: '86.0', band: 'acceptable' } },
];

const TOKENS = ['paper', 'panel', 'ink', 'ink-2', 'ink-3', 'rule', 'deep', 'deep-2', 'wash', 'rail', 'ok', 'ok-bg', 'warn', 'warn-bg', 'warn-fill', 'bad', 'bad-bg', 'pub', 'pub-bg', 'priv', 'priv-bg', 'series-availability', 'series-consistency', 'series-validity', 'series-overall'];

function isSample(sample: Sample | undefined): sample is Sample {
    return sample !== undefined;
}

function score(t: Translate, sample: Sample): ScoreValue {
    return {
        value: sample.value,
        display: sample.display,
        headline: sample.display,
        band: sample.band,
        bandLabel: sample.band === null ? t('common.no_data') : t(`enums.band.${sample.band}`),
    };
}

export default function Components() {
    const t = useTranslate();

    return (
        <>
            <div className="mx-auto flex max-w-[1280px] flex-col gap-5">
                <p className="max-w-[70ch] text-[12.5px] text-ink-2">{t('dev.intro')}</p>
                <PanelSection t={t} />
                <Section id="score-tag" title={t('dev.sections.score_tag')}>
                    <div className="flex flex-wrap gap-3">
                        {BAND_SAMPLES.map((sample) => (
                            <ScoreTag key={sample.display} score={score(t, sample)} />
                        ))}
                    </div>
                </Section>
                <Section id="status-label" title={t('dev.sections.status_label')}>
                    <div className="flex flex-wrap gap-3">
                        {BAND_SAMPLES.map((sample) => (
                            <StatusLabel key={sample.display} band={sample.band} label={score(t, sample).bandLabel} />
                        ))}
                    </div>
                </Section>
                <Section id="heat-cell" title={t('dev.sections.heat_cell')}>
                    <table className="border-collapse text-[13px]">
                        <caption className="pb-2 text-left text-[12.5px] text-ink-2">{t('dev.sample.heat_caption')}</caption>
                        <tbody>
                            <tr>
                                {HEAT_SAMPLES.map((sample) => (
                                    <HeatCell key={sample.display} score={score(t, sample)} />
                                ))}
                            </tr>
                        </tbody>
                    </table>
                </Section>
                <SegmentedSection t={t} />
                <TabsSection t={t} />
                <Section id="skeleton" title={t('dev.sections.skeleton')}>
                    <Skeleton className="h-24 w-full max-w-md" />
                </Section>
                <Section id="empty" title={t('dev.sections.empty')}>
                    <EmptyState
                        title={t('dev.sample.empty_title')}
                        description={t('dev.sample.empty_description')}
                        action={
                            <button type="button" className="rounded-md border border-rule bg-panel px-3 py-1.5 text-[13.5px] hover:bg-paper">
                                {t('dev.sample.empty_action')}
                            </button>
                        }
                    />
                </Section>
                <FigureSection t={t} />
                <TableSection t={t} />
                <Section id="visually-hidden" title={t('dev.sections.visually_hidden')}>
                    <p className="text-[13px] text-ink-2">{t('dev.sample.hidden_demo')}</p>
                    <button type="button" className="mt-2 rounded-md border border-rule px-2.5 py-1">
                        <span aria-hidden="true">✕</span>
                        <VisuallyHidden>{t('dev.sample.close')}</VisuallyHidden>
                    </button>
                </Section>
                <AnnouncerSection t={t} />
                <Section id="tokens" title={t('dev.sections.tokens')}>
                    <ul className="grid grid-cols-[repeat(auto-fill,minmax(150px,1fr))] gap-2">
                        {TOKENS.map((token) => (
                            <li key={token} className="flex items-center gap-2 text-[12.5px]">
                                <span aria-hidden="true" className="h-6 w-6 rounded border border-rule" style={{ backgroundColor: `var(--color-${token})` }} />
                                <code>{token}</code>
                            </li>
                        ))}
                    </ul>
                </Section>
            </div>
        </>
    );
}

type SectionProps = { id: string; title: string; children: ReactNode };

function Section({ id, title, children }: SectionProps) {
    return (
        <Panel labelledBy={id}>
            <PanelHeader id={id} title={title} />
            <div className="px-5 pt-3 pb-5">{children}</div>
        </Panel>
    );
}

function PanelSection({ t }: { t: Translate }) {
    return (
        <Panel labelledBy="panel-demo">
            <PanelHeader
                id="panel-demo"
                title={t('dev.sample.panel_title')}
                description={t('dev.sample.panel_description')}
                actions={
                    <button type="button" className="rounded-md border border-rule bg-panel px-3 py-1.5 text-[13.5px] hover:bg-paper">
                        {t('dev.sample.action')}
                    </button>
                }
            />
            <div className="px-5 pt-3 pb-5 text-[12.5px] text-ink-2">{t('dev.sections.panel')}</div>
        </Panel>
    );
}

type Metric = 'availability' | 'consistency' | 'validity' | 'overall';

function SegmentedSection({ t }: { t: Translate }) {
    const [metric, setMetric] = useState<Metric>('overall');

    return (
        <Section id="segmented" title={t('dev.sections.segmented')}>
            <SegmentedControl
                label={t('dev.sample.metric')}
                value={metric}
                onChange={setMetric}
                options={[
                    { value: 'availability', label: t('enums.dimension.availability') },
                    { value: 'consistency', label: t('enums.dimension.consistency') },
                    { value: 'validity', label: t('enums.dimension.validity') },
                    { value: 'overall', label: t('dev.sample.overall') },
                ]}
            />
        </Section>
    );
}

function TabsSection({ t }: { t: Translate }) {
    const { url } = usePage();
    const current = new URLSearchParams(url.split('?')[1] ?? '').get('tab') ?? 'home';
    const tab = (key: string, label: string, badge?: { text: string; label: string; tone?: 'alert' }) => ({
        href: `/dev/components?tab=${key}`,
        label,
        current: current === key,
        ...(badge === undefined ? {} : { badge }),
    });

    return (
        <Section id="tabs" title={t('dev.sections.tabs')}>
            <Tabs
                label={t('dev.sample.tabs_label')}
                items={[
                    tab('home', t('dev.sample.tab_home')),
                    tab('facility', t('dev.sample.tab_facility')),
                    tab('lga', t('dev.sample.tab_lga'), { text: '23', label: t('dev.sample.tab_lga_badge', { count: 23 }) }),
                    tab('issues', t('dev.sample.tab_issues'), { text: '12', label: t('dev.sample.tab_issues_badge', { count: 12 }), tone: 'alert' }),
                ]}
            />
        </Section>
    );
}

function FigureSection({ t }: { t: Translate }) {
    // A static sample chart: bar heights are the sample values themselves, in SVG units.
    return (
        <Panel>
            <Figure
                title={t('dev.sample.figure_title')}
                description={t('dev.sample.figure_description')}
                chart={
                    <svg role="img" aria-label={t('dev.sample.figure_chart')} viewBox="0 0 400 120" className="h-40 w-full">
                        {TREND.map((point, i) => (
                            <g key={point.round}>
                                <rect x={20 + i * 95} y={110 - (point.overall.value ?? 0)} width="60" height={point.overall.value ?? 0} rx="3" fill="var(--color-series-overall)" />
                                <text x={50 + i * 95} y={118} textAnchor="middle" fontSize="9" fill="var(--color-ink-2)">
                                    {point.round}
                                </text>
                            </g>
                        ))}
                        <line x1="10" x2="390" y1="20" y2="20" stroke="var(--color-warn-fill)" strokeDasharray="5 4" />
                    </svg>
                }
                table={
                    <DataTable
                        caption={t('dev.sample.figure_title')}
                        captionHidden
                        columns={[
                            { key: 'round', header: t('dev.sample.round') },
                            { key: 'overall', header: t('dev.sample.overall'), align: 'end' },
                        ]}
                    >
                        {TREND.map((point) => (
                            <tr key={point.round} className="border-b border-paper">
                                <th scope="row" className="px-3 py-2 text-left font-normal">
                                    {point.round}
                                </th>
                                <td className="px-3 py-2 text-right">
                                    <ScoreTag score={score(t, point.overall)} />
                                </td>
                            </tr>
                        ))}
                    </DataTable>
                }
            />
        </Panel>
    );
}

function TableSection({ t }: { t: Translate }) {
    const [sort, setSort] = useState<DataTableSort>({ column: 'overall', direction: 'asc' });
    const columns: DataTableColumn[] = [
        { key: 'lga', header: t('dev.sample.lga'), sortable: true },
        { key: 'facilities', header: t('dev.sample.facilities'), align: 'end' },
        { key: 'm1', header: t('dev.sample.month', { slot: 1 }), align: 'end' },
        { key: 'm2', header: t('dev.sample.month', { slot: 2 }), align: 'end' },
        { key: 'm3', header: t('dev.sample.month', { slot: 3 }), align: 'end' },
        { key: 'overall', header: t('dev.sample.overall'), align: 'end', sortable: true },
        { key: 'status', header: t('dev.sample.status') },
    ];
    // The sort indicator moves; the rows do not. On real pages the server sorts.
    const onSort = (column: string) =>
        setSort((current) => ({ column, direction: current.column === column && current.direction === 'asc' ? 'desc' : 'asc' }));

    return (
        <Section id="data-table" title={t('dev.sections.data_table')}>
            <DataTable caption={t('dev.sample.table_caption')} columns={columns} sort={sort} onSort={onSort}>
                {LGA_ROWS.map((row) => (
                    <tr key={row.lga} className="border-b border-paper">
                        <th scope="row" className="px-3 py-2 text-left font-normal">
                            {row.lga}
                        </th>
                        <td className="px-3 py-2 text-right tabular-nums">{row.facilities}</td>
                        {row.months.map((month, i) => (
                            <HeatCell key={i} score={score(t, month)} />
                        ))}
                        <td className="px-3 py-2 text-right">
                            <ScoreTag score={score(t, row.overall)} />
                        </td>
                        <td className="px-3 py-2">
                            <StatusLabel band={row.overall.band} label={score(t, row.overall).bandLabel} />
                        </td>
                    </tr>
                ))}
            </DataTable>
            <div className="mt-5">
                <DataTable caption={t('dev.sample.table_empty_caption')} columns={columns} empty={<EmptyState title={t('dev.sample.empty_title')} />}>
                    {[]}
                </DataTable>
            </div>
        </Section>
    );
}

function AnnouncerSection({ t }: { t: Translate }) {
    const announce = useAnnounce();
    const [last, setLast] = useState<string | null>(null);

    return (
        <Section id="announcer" title={t('dev.sections.announcer')}>
            <button
                type="button"
                className="rounded-md border border-deep bg-deep px-3 py-1.5 text-[13.5px] text-panel hover:bg-deep-2"
                onClick={() => {
                    const message = t('dev.sample.announced', { count: '439' });
                    announce(message);
                    setLast(message);
                }}
            >
                {t('dev.sample.announce')}
            </button>
            <p className="mt-2 text-[12.5px] text-ink-2">{last === null ? t('dev.sample.nothing_announced') : t('dev.sample.last_announced', { message: last })}</p>
        </Section>
    );
}
