import { useTranslate } from '@/lib/i18n';

/**
 * Shown above the public pages to a signed-in administrator only (ARCHITECTURE.md §9.0, D-77):
 * these figures include the open round, which visitors do not see. A labelled region before <main>,
 * with the way back to the admin area. Exports (Phase 6) would sit here, never on the public site.
 */
export default function AdminPreviewBar() {
    const t = useTranslate();

    return (
        <section aria-label={t('admin.preview.label')} className="border-b border-warn-fill/40 bg-warn-bg px-4 py-2 text-sm text-ink rail:px-7">
            <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span className="font-semibold">{t('admin.preview.text')}</span>
                <span aria-hidden="true">·</span>
                <a href="/admin" className="font-medium text-deep underline underline-offset-2">
                    {t('admin.preview.back')}
                </a>
            </p>
        </section>
    );
}
