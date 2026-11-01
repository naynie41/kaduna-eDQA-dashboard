import type { ReactNode } from 'react';
import PageProviders from '@/layouts/PageProviders';
import { useTranslate } from '@/lib/i18n';

export type AuthLayoutProps = {
    children: ReactNode;
};

/** Sign-in and account-security pages: one centred panel, readable from 360px up. */
export default function AuthLayout({ children }: AuthLayoutProps) {
    return (
        <PageProviders>
            <AuthFrame>{children}</AuthFrame>
        </PageProviders>
    );
}

function AuthFrame({ children }: AuthLayoutProps) {
    const t = useTranslate();

    // Two columns from 900px: the State's mark on the rail green, the form on paper. Below that the
    // mark shrinks to a band above the form, so a phone shows the form at once.
    return (
        <div className="min-h-svh bg-paper min-[900px]:grid min-[900px]:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
            <div className="flex items-center gap-3 bg-rail px-5 py-4 text-rail-ink min-[900px]:flex-col min-[900px]:items-start min-[900px]:justify-between min-[900px]:px-12 min-[900px]:py-12">
                <p className="flex items-center gap-3">
                    <span aria-hidden="true" className="grid size-10 shrink-0 place-items-center rounded-full bg-rail-active font-display text-xs font-bold tracking-[0.04em] text-panel ring-1 ring-rail-accent/70 ring-offset-2 ring-offset-rail">
                        {t('common.shell.seal')}
                    </span>
                    <span className="leading-tight">
                        <span className="block font-display text-md font-semibold text-panel">{t('common.app_name')}</span>
                        <span className="block text-xs text-rail-muted">{t('common.shell.brand_place')}</span>
                    </span>
                </p>
                <p className="hidden max-w-[14ch] font-display text-2xl leading-[1.05] font-semibold tracking-[-0.03em] text-panel min-[900px]:block">
                    {t('common.app_tagline')}
                </p>
                <p className="hidden text-xs text-rail-muted min-[900px]:block">{t('common.shell.support')}</p>
            </div>
            <div className="flex justify-center px-4 py-10 min-[900px]:items-center min-[900px]:py-16">
                <main id="main" className="w-full max-w-[26rem]">
                    <div className="rounded-panel border border-rule bg-panel p-6 sm:p-8">{children}</div>
                </main>
            </div>
        </div>
    );
}
