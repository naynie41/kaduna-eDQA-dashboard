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
        <div role="status" aria-busy="true" className={`animate-pulse rounded-md bg-paper motion-reduce:animate-none ${className}`}>
            <VisuallyHidden>{t('common.loading')}</VisuallyHidden>
        </div>
    );
}
