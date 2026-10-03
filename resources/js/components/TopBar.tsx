import { Link, usePage } from '@inertiajs/react';
import { useTranslate } from '@/lib/i18n';
import { logout } from '@/routes';

/** Who is signed in, and the way out. */
export default function TopBar() {
    const t = useTranslate();
    const { user } = usePage().props.auth;

    return (
        <header className="flex flex-wrap items-center justify-end gap-x-4 gap-y-2 border-b border-rule bg-panel px-4 py-3 lg:px-8">
            {user && <p className="text-sm text-ink-2">{t('common.user_menu.signed_in_as', { name: user.name })}</p>}
            <Link
                href={logout()}
                as="button"
                className="rounded-md border border-ink-2 px-3 py-1.5 text-sm font-medium text-ink hover:bg-wash"
            >
                {t('common.user_menu.log_out')}
            </Link>
        </header>
    );
}
