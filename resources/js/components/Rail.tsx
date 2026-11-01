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
        { label: t('common.nav.dashboard'), href: '/dashboard/home', current: path.startsWith('/dashboard'), icon: <DashboardIcon /> },
        { label: t('common.nav.administration'), href: '/admin', current: path.startsWith('/admin'), icon: <AdminIcon /> },
    ];

    return (
        <aside className="flex items-center gap-4 overflow-x-auto bg-rail px-4 py-2.5 text-rail-ink rail:sticky rail:top-0 rail:h-svh rail:flex-col rail:items-stretch rail:gap-0 rail:overflow-visible rail:px-4 rail:py-0">
            <div className="flex shrink-0 items-center gap-3 rail:-mx-4 rail:border-b rail:border-rail-line rail:px-5 rail:py-5">
                <span aria-hidden="true" className="grid size-9 shrink-0 place-items-center rounded-full bg-rail-active font-display text-2xs font-bold tracking-[0.04em] text-panel ring-1 ring-rail-accent/70 ring-offset-2 ring-offset-rail">
                    {t('common.shell.seal')}
                </span>
                <p className="hidden leading-tight min-[420px]:block">
                    <span className="block font-display text-base font-semibold tracking-[-0.01em] text-panel">{t('common.shell.brand')}</span>
                    <span className="block text-2xs text-rail-muted">{t('common.shell.brand_place')}</span>
                </p>
            </div>
            <nav aria-label={t('common.nav.label')} className="rail:pt-4">
                <ul className="flex gap-1 rail:flex-col">
                    {items.map((item) => (
                        <li key={item.href}>
                            <Link
                                href={item.href}
                                aria-current={item.current ? 'page' : undefined}
                                className={`flex items-center gap-2.5 rounded-md px-2.5 py-2 text-sm whitespace-nowrap transition-colors duration-150 ${
                                    item.current
                                        ? 'bg-rail-active font-semibold text-panel forced-colors:forced-color-adjust-none forced-colors:bg-[Highlight] forced-colors:text-[HighlightText]'
                                        : 'text-rail-ink hover:bg-white/6 hover:text-panel'
                                }`}
                            >
                                <span className={`hidden rail:inline-flex ${item.current ? 'text-rail-accent' : 'text-rail-muted'}`}>{item.icon}</span>
                                {item.label}
                            </Link>
                        </li>
                    ))}
                </ul>
            </nav>
            {props.shell !== null && (
                <div className="mt-auto hidden pb-5 text-2xs text-rail-muted rail:block">
                    <p className="border-t border-rail-line pt-4">
                        <span className="block font-display text-xl font-semibold tracking-[-0.02em] text-panel tabular-nums">{formatCount(props.shell.assessments)}</span>
                        <span className="mt-1 block text-rail-ink">{t('common.shell.assessments_label')}</span>
                    </p>
                    <p className="mt-3">{t('common.shell.support')}</p>
                </div>
            )}
        </aside>
    );
}

// Interface icons, drawn on one 16px grid at a 1.5 stroke (decorative: the link text names them).
function DashboardIcon() {
    return (
        <svg aria-hidden="true" focusable="false" viewBox="0 0 16 16" className="size-4" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round">
            <path d="M2.5 13.5V8.5M6.5 13.5v-9M10.5 13.5v-6M14 13.5H2" />
            <path d="M13.5 13.5V2.5" />
        </svg>
    );
}

function AdminIcon() {
    return (
        <svg aria-hidden="true" focusable="false" viewBox="0 0 16 16" className="size-4" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round">
            <path d="M2 4h7M12 4h2M2 8h2M7 8h7M2 12h8M13 12h1" />
            <circle cx="10.5" cy="4" r="1.5" />
            <circle cx="5.5" cy="8" r="1.5" />
            <circle cx="11.5" cy="12" r="1.5" />
        </svg>
    );
}
