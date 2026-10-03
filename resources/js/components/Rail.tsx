import { Link, usePage } from '@inertiajs/react';
import { useTranslate } from '@/lib/i18n';
import { home } from '@/routes';
import { edit as securityEdit } from '@/routes/security';

/** The left navigation rail (a top bar below the large breakpoint). */
export default function Rail() {
    const t = useTranslate();
    const { url } = usePage();
    const items = [
        { label: t('common.nav.dashboard'), href: home().url, current: url === '/' },
        { label: t('common.nav.settings'), href: securityEdit().url, current: url.startsWith('/settings') },
    ];

    return (
        <nav aria-label={t('common.nav.label')} className="bg-rail text-panel lg:min-h-svh lg:w-56 lg:shrink-0">
            <div className="flex items-center gap-4 px-4 py-3 lg:flex-col lg:items-stretch lg:gap-1 lg:py-6">
                <p className="font-display text-lg font-semibold lg:mb-6 lg:px-3">{t('common.app_name')}</p>
                <ul className="flex gap-1 lg:flex-col">
                    {items.map((item) => (
                        <li key={item.href}>
                            <Link
                                href={item.href}
                                aria-current={item.current ? 'page' : undefined}
                                className={`block rounded-md px-3 py-2 ${item.current ? 'bg-deep-2 font-semibold' : 'hover:bg-deep'}`}
                            >
                                {item.label}
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>
        </nav>
    );
}
