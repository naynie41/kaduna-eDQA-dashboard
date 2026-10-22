import EmptyState from '@/components/EmptyState';
import Panel from '@/components/Panel';
import { useTranslate } from '@/lib/i18n';

/** An administration page whose content arrives in a later Phase 5 step (ARCHITECTURE.md §9.3). */
export default function AdminPlaceholder() {
    const t = useTranslate();

    return (
        <Panel>
            <EmptyState title={t('admin.placeholder.title')} description={t('admin.placeholder.description')} />
        </Panel>
    );
}
