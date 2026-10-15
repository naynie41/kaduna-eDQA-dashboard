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
        <header ref={headerRef} className="z-20 border-b border-rule bg-panel px-4 pt-3.5 rail:sticky rail:top-0 rail:px-6">
            <div className="flex flex-wrap items-start gap-x-4 gap-y-3 pb-3">
                <div className="min-w-0 flex-1 basis-72">
                    {title !== null && (
                        <h1 ref={titleRef} tabIndex={-1} className="text-[17px] outline-offset-4">
                            {title}
                        </h1>
                    )}
                    {subtitle !== undefined && subtitle !== null && <p className="mt-0.5 max-w-[70ch] text-[12.5px] text-ink-2">{subtitle}</p>}
                </div>
                <nav aria-label={t('common.user_menu.label')} className="flex items-center gap-3 text-[12.5px]">
                    {user && <span className="text-ink-2">{t('common.user_menu.signed_in_as', { name: user.name })}</span>}
                    <Link href={securityEdit()} className="text-deep-2 underline-offset-2 hover:underline">
                        {t('common.user_menu.security')}
                    </Link>
                    <Link href={logout()} as="button" className="rounded-md border border-rule px-2.5 py-1 font-medium whitespace-nowrap text-ink hover:bg-paper">
                        {t('common.user_menu.log_out')}
                    </Link>
                </nav>
            </div>
            {children}
        </header>
    );
}
