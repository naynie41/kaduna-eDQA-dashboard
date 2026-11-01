import type { ReactNode, Ref } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { useTranslate } from '@/lib/i18n';
import { logout } from '@/routes';
import { edit as securityEdit } from '@/routes/security';

export type TopBarProps = {
    /** The header itself, so the shell can measure it for scroll-padding-top. */
    headerRef?: Ref<HTMLElement>;
    title: string | null;
    subtitle?: string | null;
    /** Receives focus when the page changes (not when a filter does). */
    titleRef?: Ref<HTMLHeadingElement>;
    /** The filter bar and the page tabs, on dashboard pages. */
    children?: ReactNode;
};

/** The top bar (sticky from 760px; on a phone it would cover most of the screen): the page's h1 and subtitle, the account links, then filters and tabs. */
export default function TopBar({ title, subtitle, titleRef, headerRef, children }: TopBarProps) {
    const t = useTranslate();
    const { user } = usePage().props.auth;

    return (
        <header ref={headerRef} className="z-20 border-b border-rule-strong bg-panel px-4 pt-4 rail:sticky rail:top-0 rail:px-7 rail:pt-5">
            <div className="flex flex-wrap items-start gap-x-6 gap-y-3 pb-4">
                <div className="min-w-0 flex-1 basis-80">
                    {title !== null && (
                        <h1 ref={titleRef} tabIndex={-1} className="text-lg font-semibold tracking-[-0.02em] text-ink outline-offset-4">
                            {title}
                        </h1>
                    )}
                    {subtitle !== undefined && subtitle !== null && <p className="mt-1 max-w-[72ch] text-sm text-ink-2">{subtitle}</p>}
                </div>
                <nav aria-label={t('common.user_menu.label')} className="flex items-center gap-1 text-xs">
                    {user && <span className="mr-2 hidden text-ink-2 sm:inline">{t('common.user_menu.signed_in_as', { name: user.name })}</span>}
                    <Link href={securityEdit()} className="btn btn-quiet btn-sm">
                        {t('common.user_menu.security')}
                    </Link>
                    <Link href={logout()} as="button" className="btn btn-secondary btn-sm">
                        {t('common.user_menu.log_out')}
                    </Link>
                </nav>
            </div>
            {children}
        </header>
    );
}
