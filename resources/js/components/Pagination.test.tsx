import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import Pagination from '@/components/Pagination';
import { axeCheck, renderUi, testTranslations } from '@/test/render';

const translations = {
    ...testTranslations,
    dashboard: {
        facility: {
            showing: 'Showing :from–:to of :total facilities',
            showing_none: 'No facilities match these filters.',
            pages: 'Facility pages',
            previous: 'Previous',
            next: 'Next',
            page: 'Page :page',
        },
    },
};

describe('Pagination', () => {
    it('says the range in words and offers labelled page buttons, the current one marked', async () => {
        const { container } = renderUi(<Pagination page={2} perPage={50} total={2184} onPage={() => undefined} />, translations);

        expect(screen.getByText('Showing 51–100 of 2,184 facilities')).toBeTruthy();
        const nav = screen.getByRole('navigation', { name: 'Facility pages' });
        expect(within(nav).getByRole('button', { name: 'Page 2' }).getAttribute('aria-current')).toBe('page');
        expect(within(nav).getAllByRole('button').map((b) => b.getAttribute('aria-label') ?? b.textContent)).toEqual([
            'Previous', 'Page 1', 'Page 2', 'Page 3', 'Page 44', 'Next',
        ]);
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('asks for the neighbouring pages and cannot go before the first', async () => {
        const onPage = vi.fn();
        renderUi(<Pagination page={1} perPage={50} total={120} onPage={onPage} />, translations);

        expect((screen.getByRole('button', { name: 'Previous' }) as HTMLButtonElement).disabled).toBe(true);
        await userEvent.click(screen.getByRole('button', { name: 'Next' }));
        await userEvent.click(screen.getByRole('button', { name: 'Page 3' }));
        expect(onPage.mock.calls).toEqual([[2], [3]]);
    });

    it('has no page buttons for a single page, and says when nothing matches', () => {
        renderUi(<Pagination page={1} perPage={50} total={0} onPage={() => undefined} />, translations);

        expect(screen.getByText('No facilities match these filters.')).toBeTruthy();
        expect(screen.queryByRole('navigation')).toBeNull();
    });
});
