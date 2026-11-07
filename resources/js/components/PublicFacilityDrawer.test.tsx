import { screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import FacilityDrawer from '@/components/FacilityDrawer';
import { axeCheck, renderUi, testTranslations } from '@/test/render';
import type { PublicFacilityDetail } from '@/types/dashboard';

// The public facility drawer (ARCHITECTURE.md §9.2.7, SECURITY.md §3.1): it asks only
// GET /api/public/facilities/{id} and shows the score card, item results, history, level and
// ownership. A signed-in administrator previewing the site also gets a link to the admin view.

const page = vi.hoisted(() => ({ props: { auth: { user: null as { id: number; name: string; email: string } | null } } }));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();

    return { ...actual, usePage: () => page };
});

const score = (display: string, band: 'strong' | 'review' | null = 'review') => ({
    value: display === '—' ? null : Number(display),
    display,
    headline: display,
    band,
    bandLabel: band === null ? '' : band === 'strong' ? 'Strong' : 'Review',
});

const detail: PublicFacilityDetail = {
    facility: { name: 'Facility F1', lga: 'Alpha', ward: 'Ward F1', level: 'primary', level_label: 'Primary', ownership: 'public', ownership_label: 'Public' },
    round: { id: 1, label: 'Q1 2026', year: 2026, quarter: 1 },
    scores: { availability: score('75.0'), consistency: score('75.0'), validity: score('75.0'), overall: score('75.0') },
    items: [
        {
            dimension: 'availability',
            dimension_label: 'Availability',
            slot: 1,
            month: 'January 2026',
            score: score('75.0'),
            items: [
                { code: 'item_1', label: 'Register in place', result: 'pass', checks: null },
                { code: 'item_2', label: 'Monthly summary filed', result: 'fail', checks: null },
            ],
        },
    ],
    history: [{ round_id: 1, label: 'Q1 2026', overall: score('75.0'), selected: true }],
};

const translations = {
    ...testTranslations,
    enums: { dimension: { availability: 'Availability', consistency: 'Consistency', validity: 'Validity' } },
    dashboard: {
        drawer: {
            loading_title: 'Facility details',
            loading: 'Loading…',
            error: 'Could not load.',
            not_found: 'No such facility.',
            retry: 'Try again',
            close: 'Close',
            admin_view: 'Open the admin facility view',
            place: ':lga LGA · :ward ward',
            details: 'Facility',
            level: 'Level',
            owner: 'Owner',
            scores_title: 'Scores, :round',
            scores_title_none: 'Scores',
            not_assessed: 'Not assessed in :round.',
            never_assessed: 'No round in view yet.',
            overall: 'Overall',
            items_title: 'Item responses',
            items_none: 'No item responses for this round.',
            results: { pass: 'Pass', fail: 'Fail', na: 'N/A', unknown: 'Not scored', count: ':passed of :total' },
            history_title: 'Overall by round',
            history_none: 'No scored rounds yet.',
            history_single: 'One scored round: :score in :round.',
            history_summary: ':count scored rounds.',
        },
    },
};

const fetchMock = vi.fn();

beforeEach(() => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: detail }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
    fetchMock.mockReset();
    page.props.auth.user = null;
});

describe('Public facility drawer', () => {
    it('asks only the public endpoint, for the round the page shows', async () => {
        renderUi(<FacilityDrawer facilityId={7} name="Facility F1" year="2026" quarter="1" onClose={() => {}} />, translations);

        await screen.findByText('Register in place');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(String(fetchMock.mock.calls[0]?.[0])).toBe('/api/public/facilities/7?year=2026&quarter=1');
    });

    it('shows the score card, the item results in words, the history, level and ownership', async () => {
        renderUi(<FacilityDrawer facilityId={7} onClose={() => {}} />, translations);

        await screen.findByRole('heading', { name: 'Scores, Q1 2026' });
        expect(screen.getByText('Alpha LGA · Ward F1 ward')).toBeTruthy();
        expect(screen.getByText('Pass')).toBeTruthy();
        expect(screen.getByText('Fail')).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Overall by round' })).toBeTruthy();
        expect(screen.getByText('Primary')).toBeTruthy();
        expect(screen.getByText('Public')).toBeTruthy();
        expect(await axeCheck(document.body)).toHaveNoViolations();
    });

    it('offers a visitor no link to the admin area', async () => {
        const { container } = renderUi(<FacilityDrawer facilityId={7} onClose={() => {}} />, translations);

        await screen.findByText('Register in place');
        expect(screen.queryByRole('link', { name: 'Open the admin facility view' })).toBeNull();
        expect(Array.from(container.ownerDocument.querySelectorAll('a')).some((a) => (a.getAttribute('href') ?? '').startsWith('/admin'))).toBe(false);
    });

    it('links a signed-in administrator to the admin facility view', async () => {
        page.props.auth.user = { id: 1, name: 'Amina Bello', email: 'amina@example.test' };
        renderUi(<FacilityDrawer facilityId={7} onClose={() => {}} />, translations);

        await waitFor(() => expect(screen.getByRole('link', { name: 'Open the admin facility view' }).getAttribute('href')).toBe('/admin/facilities/7'));
    });

    it('says when the facility has no closed round to show', async () => {
        fetchMock.mockResolvedValue(
            new Response(JSON.stringify({ data: { ...detail, round: null, items: [], history: [], scores: { availability: score('—', null), consistency: score('—', null), validity: score('—', null), overall: score('—', null) } } }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }),
        );
        renderUi(<FacilityDrawer facilityId={7} onClose={() => {}} />, translations);

        expect(await screen.findByText('No round in view yet.')).toBeTruthy();
        expect(screen.getByText('No item responses for this round.')).toBeTruthy();
    });
});
