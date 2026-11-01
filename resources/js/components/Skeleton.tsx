import VisuallyHidden from '@/components/VisuallyHidden';
import { useTranslate } from '@/lib/i18n';

export type SkeletonProps = {
    /** Fixed size (e.g. "h-52 w-full"), so the layout does not jump when the content arrives. */
    className?: string;
};

/** A placeholder while a chart or deferred prop loads: busy, and says "Loading". */
export default function Skeleton({ className = 'h-40 w-full' }: SkeletonProps) {
    const t = useTranslate();

    return (
        <div
            role="status"
            aria-busy="true"
            className={`rounded-panel border border-rule bg-[linear-gradient(100deg,var(--color-paper)_30%,var(--color-panel)_50%,var(--color-paper)_70%)] bg-[length:200%_100%] motion-safe:animate-[shimmer_1.6s_linear_infinite] ${className}`}
        >
            <VisuallyHidden>{t('common.loading')}</VisuallyHidden>
        </div>
    );
}
