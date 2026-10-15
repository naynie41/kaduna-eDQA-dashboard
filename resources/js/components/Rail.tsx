import { Link, usePage } from '@inertiajs/react';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';

/**
 * The left rail (ARCHITECTURE.md §9.1): the KD seal and "eDQA e-tool / Kaduna State", the two
 * workspaces, and a footer with the assessment count and "Support: HSDF". Below 760px it becomes
 * a bar across the top and the footer is left out.
 */
export default function Rail() {
    const t = useTranslate();
    const { url, props } = usePage();
    const path = url.split('?')[0] ?? '';
    const items = [
        { label: t('common.nav.dashboard'), href: '/dashboard/home', current: path.startsWith('/dashboard') },
        { label: t('common.nav.administration'), href: '/admin', current: path.startsWith('/admin') },
    ];

    return (
        <aside className="flex items-center gap-4 overflow-x-auto bg-rail px-4 py-3 text-rail-ink rail:sticky rail:top-0 rail:h-svh rail:flex-col rail:items-stretch rail:gap-6 rail:overflow-visible rail:px-3.5 rail:py-5">
            <div className="flex shrink-0 items-start gap-2.5">
                <span aria-hidden="true" className="grid h-[34px] w-[34px] shrink-0 place-items-center rounded-full border-[1.5px] border-rail-accent font-display text-[11px] font-bold text-panel">
                    {t('common.shell.seal')}
                </span>
                <p className="leading-tight">
                    <span className="block font-display text-sm font-semibold text-panel">{t('common.shell.brand')}</span>
                    <span className="block text-[11.5px] text-rail-muted">{t('common.shell.brand_place')}</span>
                </p>
            </div>
            <nav aria-label={t('common.nav.label')}>
                <ul className="flex gap-1.5 rail:flex-col rail:gap-0.5">
                    {items.map((item) => (
                        <li key={item.href}>
                            <Link
                                href={item.href}
                                aria-current={item.current ? 'page' : undefined}
                                className={`flex items-center gap-2 rounded-md px-2.5 py-2 text-sm whitespace-nowrap ${
                                    item.current ? 'bg-rail-active font-medium text-panel shadow-[inset_2px_0_0_var(--color-rail-accent)]' : 'hover:bg-white/5 hover:text-panel'
                                }`}
                            >
                                {item.label}
                            </Link>
                        </li>
                    ))}
                </ul>
            </nav>
            {props.shell !== null && (
                <p className="mt-auto hidden border-t border-rail-line pt-3.5 text-[11.5px] text-rail-muted rail:block">
                    <span className="block font-medium text-rail-ink">{t('common.shell.assessments', { count: formatCount(props.shell.assessments) })}</span>
                    {t('common.shell.support')}
                </p>
            )}
        </aside>
    );
}
