import { Link, usePage } from '@inertiajs/react';
import { useTranslate } from '@/lib/i18n';
import { logout } from '@/routes';
import { edit as securityEdit } from '@/routes/admin/security';

/** The admin layout's account links: who is signed in, the security settings and log out. */
export default function AccountMenu() {
    const t = useTranslate();
    const { user } = usePage().props.auth;

    if (user === null) {
        return null;
    }

    return (
        <nav aria-label={t('admin.user_menu.label')} className="flex items-center gap-1 text-xs">
            <span className="mr-2 hidden text-ink-2 sm:inline">{t('admin.user_menu.signed_in_as', { name: user.name })}</span>
            <Link href={securityEdit()} className="btn btn-quiet btn-sm">
                {t('admin.user_menu.security')}
            </Link>
            <Link href={logout()} as="button" className="btn btn-secondary btn-sm">
                {t('admin.user_menu.log_out')}
            </Link>
        </nav>
    );
}
