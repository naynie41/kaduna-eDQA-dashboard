import type { ReactNode } from 'react';
import Rail from '@/components/Rail';
import TopBar from '@/components/TopBar';
import { useTranslate } from '@/lib/i18n';

export type AppShellProps = {
    children: ReactNode;
};

/** Every signed-in page: skip link, rail, top bar, main content (CONVENTION.md §6). */
export default function AppShell({ children }: AppShellProps) {
    const t = useTranslate();

    return (
        <div className="min-h-svh lg:flex">
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-panel focus:px-4 focus:py-2"
            >
                {t('common.skip_to_content')}
            </a>
            <Rail />
            <div className="min-w-0 flex-1">
                <TopBar />
                <main id="main" tabIndex={-1} className="px-4 py-6 lg:px-8">
                    {children}
                </main>
            </div>
        </div>
    );
}
