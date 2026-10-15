import { useState } from 'react';
import { act, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { useAnnounce } from '@/components/Announcer';
import DataTable, { type DataTableColumn } from '@/components/DataTable';
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
import { axeCheck, renderUi } from '@/test/render';
import type { Band, ScoreValue } from '@/types/score';

const score = (overrides: Partial<ScoreValue> = {}): ScoreValue => ({
    value: 92.25, display: '92.3', headline: '92', band: 'strong', bandLabel: 'Strong', ...overrides,
});
const noData: ScoreValue = { value: null, display: '—', headline: '—', band: null, bandLabel: 'No data' };

const BANDS: [Band, string][] = [['strong', 'Strong'], ['acceptable', 'Acceptable'], ['review', 'Review'], ['needs_action', 'Needs action']];

describe('the axe check', () => {
    it('really fails on a violation, so the passes below mean something', async () => {
        const { container } = renderUi(<button type="button" />); // a button with no name

        expect(await axeCheck(container)).not.toHaveNoViolations();
    });
});

describe('Panel and PanelHeader', () => {
    it('is a region named by its heading, with the description and actions beside it', async () => {
        const { container } = renderUi(
            <Panel labelledBy="dims">
                <PanelHeader id="dims" title="The three dimensions" description="Each facility is scored out of 100." actions={<button type="button">Export</button>} />
            </Panel>,
        );

        const region = screen.getByRole('region', { name: 'The three dimensions' });
        expect(within(region).getByRole('heading', { level: 2, name: 'The three dimensions' })).toBeTruthy();
        expect(within(region).getByText('Each facility is scored out of 100.')).toBeTruthy();
        expect(within(region).getByRole('button', { name: 'Export' })).toBeTruthy();
        expect(await axeCheck(container)).toHaveNoViolations();
    });
});

describe('ScoreTag', () => {
    it.each(BANDS)('shows the value and "%s" as text', async (band, label) => {
        const { container } = renderUi(<ScoreTag score={score({ band, bandLabel: label })} />);

        expect(container.textContent).toBe(`92.3${label}`);
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('shows no data in words', () => {
        const { container } = renderUi(<ScoreTag score={noData} />);

        expect(container.textContent).toBe('—No data');
    });
});

describe('StatusLabel', () => {
    it.each(BANDS)('says "%s" in words, with a decorative mark only', async (band, label) => {
        const { container } = renderUi(<StatusLabel band={band} label={label} />);

        expect(screen.getByText(label)).toBeTruthy();
        expect(container.querySelector('[aria-hidden="true"]')).not.toBeNull();
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('says No data when there is no band', () => {
        renderUi(<StatusLabel band={null} label="No data" />);

        expect(screen.getByText('No data')).toBeTruthy();
    });
});

describe('HeatCell', () => {
    const table = (cell: React.ReactNode) => (
        <table>
            <caption>Month scores</caption>
            <thead><tr><th scope="col">April</th></tr></thead>
            <tbody><tr>{cell}</tr></tbody>
        </table>
    );

    it('shows the number the server sent on its shade, in ink', async () => {
        const { container } = renderUi(table(<HeatCell score={score({ value: 100, display: '100.0' })} />));

        const cell = screen.getByRole('cell', { name: '100.0' });
        expect(cell.style.backgroundColor).not.toBe('');
        expect(cell.style.color).toBe('var(--color-ink)');
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('says No data in an unshaded cell when there is no score', () => {
        renderUi(table(<HeatCell score={noData} />));

        const cell = screen.getByRole('cell');
        expect(cell.textContent).toContain('No data');
        expect(cell.style.backgroundColor).toBe('');
    });
});

describe('SegmentedControl', () => {
    function Metric() {
        const [value, setValue] = useState<'overall' | 'availability'>('overall');

        return (
            <SegmentedControl
                label="Metric"
                value={value}
                onChange={setValue}
                options={[{ value: 'availability', label: 'Availability' }, { value: 'overall', label: 'Overall' }]}
            />
        );
    }

    it('is a labelled group of toggle buttons; the chosen one is pressed', async () => {
        const { container } = renderUi(<Metric />);

        const group = screen.getByRole('group', { name: 'Metric' });
        expect(within(group).getByRole('button', { name: 'Overall', pressed: true })).toBeTruthy();

        await userEvent.click(within(group).getByRole('button', { name: 'Availability' }));
        expect(within(group).getByRole('button', { name: 'Availability', pressed: true })).toBeTruthy();
        expect(within(group).getByRole('button', { name: 'Overall', pressed: false })).toBeTruthy();
        expect(await axeCheck(container)).toHaveNoViolations();
    });
});

describe('Tabs', () => {
    it('is a navigation of links, the current one marked aria-current="page", not a tablist', async () => {
        const { container } = renderUi(
            <Tabs
                label="Dashboard pages"
                items={[
                    { href: '/', label: 'Home', current: true },
                    { href: '/lga', label: 'LGA view', current: false, badge: { text: '23', label: '23 local governments' } },
                ]}
            />,
        );

        const nav = screen.getByRole('navigation', { name: 'Dashboard pages' });
        expect(within(nav).getByRole('link', { name: 'Home' }).getAttribute('aria-current')).toBe('page');
        expect(within(nav).getByRole('link', { name: /LGA view/ }).getAttribute('aria-current')).toBeNull();
        expect(within(nav).getByText('23 local governments')).toBeTruthy();
        expect(screen.queryByRole('tablist')).toBeNull();
        expect(await axeCheck(container)).toHaveNoViolations();
    });
});

describe('Skeleton', () => {
    it('is busy and says it is loading', async () => {
        const { container } = renderUi(<Skeleton className="h-40" />);

        const status = screen.getByRole('status');
        expect(status.getAttribute('aria-busy')).toBe('true');
        expect(status.textContent).toBe('Loading');
        expect(await axeCheck(container)).toHaveNoViolations();
    });
});

describe('EmptyState', () => {
    it('says why there is nothing, in words', async () => {
        const { container } = renderUi(<EmptyState title="No facilities match these filters." description="Clear a filter to see more." />);

        expect(screen.getByText('No facilities match these filters.')).toBeTruthy();
        expect(screen.getByText('Clear a filter to see more.')).toBeTruthy();
        expect(await axeCheck(container)).toHaveNoViolations();
    });
});

describe('Figure', () => {
    it('captions the chart and swaps it for an accessible table on request', async () => {
        const { container } = renderUi(
            <Figure
                title="Quality score by quarter"
                description="Overall score in each round."
                chart={<svg role="img" aria-label="Line chart of the overall score" />}
                table={<table><caption>Quality score by quarter</caption><tbody><tr><th scope="row">Q2 2026</th><td>86.0</td></tr></tbody></table>}
            />,
        );

        const figure = screen.getByRole('figure', { name: 'Quality score by quarter' });
        expect(within(figure).getByRole('img', { name: 'Line chart of the overall score' })).toBeTruthy();
        expect(await axeCheck(container)).toHaveNoViolations();

        await userEvent.click(within(figure).getByRole('button', { name: 'Show as table' }));
        expect(within(figure).getByRole('table', { name: 'Quality score by quarter' })).toBeTruthy();
        expect(within(figure).queryByRole('img')).toBeNull();
        expect(within(figure).getByRole('button', { name: 'Show as chart' }).getAttribute('aria-controls')).toBeTruthy();
        expect(await axeCheck(container)).toHaveNoViolations();
    });
});

describe('DataTable', () => {
    const columns: DataTableColumn[] = [
        { key: 'lga', header: 'LGA', sortable: true },
        { key: 'facilities', header: 'Facilities', align: 'end' },
        { key: 'overall', header: 'Overall', align: 'end', sortable: true },
    ];

    it('has a caption, column headers with scope, and a focusable named scroll region', async () => {
        const { container } = renderUi(
            <DataTable caption="LGA results" columns={columns} sort={{ column: 'overall', direction: 'asc' }} onSort={() => undefined}>
                <tr><th scope="row">Zaria</th><td>25</td><td>83.2</td></tr>
            </DataTable>,
        );

        expect(screen.getByRole('table', { name: 'LGA results' })).toBeTruthy();
        expect(screen.getAllByRole('columnheader').map((h) => h.getAttribute('scope'))).toEqual(['col', 'col', 'col']);
        const region = screen.getByRole('region', { name: 'LGA results, scrollable' });
        expect(region.tabIndex).toBe(0);
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('marks the sorted column with aria-sort and sorts through buttons', async () => {
        const onSort = vi.fn();
        renderUi(
            <DataTable caption="LGA results" columns={columns} sort={{ column: 'overall', direction: 'asc' }} onSort={onSort}>
                <tr><th scope="row">Zaria</th><td>25</td><td>83.2</td></tr>
            </DataTable>,
        );

        const [lga, facilities, overall] = screen.getAllByRole('columnheader');
        expect(overall?.getAttribute('aria-sort')).toBe('ascending');
        expect(lga?.getAttribute('aria-sort')).toBe('none');
        expect(facilities?.hasAttribute('aria-sort')).toBe(false);

        await userEvent.click(screen.getByRole('button', { name: 'LGA' }));
        expect(onSort).toHaveBeenCalledWith('lga');
        expect(screen.queryByRole('button', { name: 'Facilities' })).toBeNull();
    });

    it('shows the empty state across every column when there are no rows', () => {
        renderUi(<DataTable caption="LGA results" columns={columns} empty={<EmptyState title="Nothing to show." />}>{[]}</DataTable>);

        expect(screen.getByRole('cell', { name: 'Nothing to show.' }).getAttribute('colspan')).toBe('3');
    });
});

describe('VisuallyHidden', () => {
    it('keeps text for assistive technology, off screen', () => {
        renderUi(<button type="button">⨯<VisuallyHidden>Close</VisuallyHidden></button>);

        const button = screen.getByRole('button', { name: /Close/ });
        expect(within(button).getByText('Close').className).toContain('sr-only');
    });
});

describe('Announcer', () => {
    function Saver() {
        const announce = useAnnounce();

        return <button type="button" onClick={() => announce('Filters applied: 439 facilities')}>Apply</button>;
    }

    it('is one polite live region, and useAnnounce speaks through it', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        renderUi(<Saver />);

        const regions = document.querySelectorAll('[aria-live]');
        expect(regions).toHaveLength(1);
        expect(regions[0]?.getAttribute('aria-live')).toBe('polite');

        await userEvent.click(screen.getByRole('button', { name: 'Apply' }));
        act(() => {
            vi.advanceTimersByTime(200);
        });
        expect(regions[0]?.textContent).toBe('Filters applied: 439 facilities');
        vi.useRealTimers();
    });
});
