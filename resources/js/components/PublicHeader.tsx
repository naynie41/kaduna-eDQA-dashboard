import { Link } from '@inertiajs/react';
import { useTranslate } from '@/lib/i18n';

/**
 * The public site's header (ARCHITECTURE.md §9.1): the KD seal and "eDQA e-tool / Kaduna State",
 * linking to Home. No Administration link and no login link (D-78).
 */
export default function PublicHeader() {
    const t = useTranslate();

    return (
        // Not a <header>: the top bar below is the page's one banner landmark.
        <div className="bg-rail px-4 py-3 text-rail-ink rail:px-7">
            <Link href="/" className="inline-flex items-center gap-3 rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-panel">
                <span aria-hidden="true" className="grid size-9 shrink-0 place-items-center rounded-full bg-rail-active font-display text-2xs font-bold tracking-[0.04em] text-panel ring-1 ring-rail-accent/70 ring-offset-2 ring-offset-rail">
                    {t('common.shell.seal')}
                </span>
                <span className="leading-tight">
                    <span className="block font-display text-base font-semibold tracking-[-0.01em] text-panel">{t('common.shell.brand')}</span>
                    <span className="block text-2xs text-rail-muted">{t('common.shell.brand_place')}</span>
                </span>
            </Link>
        </div>
    );
}
