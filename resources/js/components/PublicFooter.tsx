import { formatDate } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';

export type PublicFooterProps = {
    /** When the public figures last changed (D-81); null while nothing is published. */
    updatedAt: string | null;
};

/** The public site's footer (ARCHITECTURE.md §9.1): "Support: HSDF" and "Figures last updated {date}". */
export default function PublicFooter({ updatedAt }: PublicFooterProps) {
    const t = useTranslate();
    const months = useTranslationList('common.months_short');

    return (
        <footer className="border-t border-rule bg-panel px-4 py-4 text-sm text-ink-2 rail:px-7">
            <div className="mx-auto flex max-w-[1320px] flex-wrap justify-between gap-x-6 gap-y-1">
                <p>{t('common.shell.support')}</p>
                {updatedAt !== null && months.length === 12 && (
                    <p>
                        {t('dashboard.footer.updated')}{' '}
                        <time dateTime={updatedAt}>{formatDate(updatedAt, months)}</time>
                    </p>
                )}
            </div>
        </footer>
    );
}
