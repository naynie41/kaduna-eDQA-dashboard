import { Deferred } from '@inertiajs/react';
import EmptyState from '@/components/EmptyState';
import Panel from '@/components/Panel';
import Skeleton from '@/components/Skeleton';
import { useTranslate } from '@/lib/i18n';

export type PagePlaceholderProps = {
    /** The page's deferred props: loaded behind a fixed-size skeleton, as the real page will. */
    deferred: string[];
};

/** A dashboard page whose content arrives in a later Phase 4 step; its data already loads. */
export default function PagePlaceholder({ deferred }: PagePlaceholderProps) {
    const t = useTranslate();

    return (
        <Deferred data={deferred} fallback={<Skeleton className="h-64 w-full" />}>
            <Panel>
                <EmptyState title={t('dashboard.placeholder.title')} description={t('dashboard.placeholder.description')} />
            </Panel>
        </Deferred>
    );
}
