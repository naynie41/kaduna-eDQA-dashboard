import EmptyState from '@/components/EmptyState';
import Panel from '@/components/Panel';
import { useTranslate } from '@/lib/i18n';

/** Administration landing (ARCHITECTURE.md §9.3) until its five pages arrive in Phase 5. */
export default function AdminIndex() {
    const t = useTranslate();

    return (
        <Panel>
            <EmptyState title={t('common.pages.admin.placeholder')} />
        </Panel>
    );
}
