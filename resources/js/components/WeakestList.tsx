import DataTable from '@/components/DataTable';
import EmptyState from '@/components/EmptyState';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import ScoreTag from '@/components/ScoreTag';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Weakest } from '@/types/dashboard';

export type WeakestListProps = {
    weakest: Weakest;
    /** The chosen LGA's name, for "Weakest wards in {LGA}". */
    lgaName: string | null;
};

/** The six lowest LGAs (or, with an LGA chosen, its wards), lowest first, each with its score tag. */
export default function WeakestList({ weakest, lgaName }: WeakestListProps) {
    const t = useTranslate();
    const wards = weakest.scope === 'wards';
    const lga = lgaName ?? '';
    const title = wards ? t('dashboard.home.weakest_wards_title', { lga }) : t('dashboard.home.weakest_lgas_title');
    const rows = weakest.rows.map((row) => ({ key: 'wardId' in row ? row.wardId : row.lgaId, name: row.name, facilities: row.facilities, overall: row.overall }));

    return (
        <Panel labelledBy="home-weakest">
            <PanelHeader id="home-weakest" title={title} description={wards ? t('dashboard.home.weakest_wards_intro', { lga }) : t('dashboard.home.weakest_lgas_intro')} />
            <div className="pt-2 pb-2">
                <DataTable
                    caption={title}
                    captionHidden
                    columns={[
                        { key: 'name', header: wards ? t('dashboard.home.weakest_ward') : t('dashboard.home.weakest_lga') },
                        { key: 'facilities', header: t('dashboard.home.facilities'), align: 'end' },
                        { key: 'overall', header: t('dashboard.home.overall'), align: 'end' },
                    ]}
                    empty={<EmptyState title={t('dashboard.home.nothing_to_rank')} />}
                >
                    {rows.map((row) => (
                        <tr key={row.key} className="border-b border-paper last:border-b-0">
                            <th scope="row" className="px-3 py-2 text-left font-normal">
                                {row.name}
                            </th>
                            <td className="px-3 py-2 text-right tabular-nums">{formatCount(row.facilities)}</td>
                            <td className="px-3 py-2 text-right">
                                <ScoreTag score={row.overall} />
                            </td>
                        </tr>
                    ))}
                </DataTable>
            </div>
        </Panel>
    );
}
