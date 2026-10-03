import type { ReactNode } from 'react';
import { useTranslate } from '@/lib/i18n';

export type AuthLayoutProps = {
    children: ReactNode;
};

/** Sign-in and account-security pages: one centred panel, readable from 360px up. */
export default function AuthLayout({ children }: AuthLayoutProps) {
    const t = useTranslate();

    return (
        <div className="flex min-h-svh flex-col items-center justify-center px-4 py-10">
            <main id="main" className="w-full max-w-md">
                <p className="mb-6 text-center">
                    <span className="block font-display text-xl font-semibold text-deep">{t('common.app_name')}</span>
                    <span className="block text-sm text-ink-2">{t('common.app_tagline')}</span>
                </p>
                <div className="rounded-[var(--radius-panel)] bg-panel p-6 shadow-[var(--shadow-panel)] sm:p-8">{children}</div>
            </main>
        </div>
    );
}
