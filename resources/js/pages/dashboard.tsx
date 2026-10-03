import { Head } from '@inertiajs/react';
import { useTranslate } from '@/lib/i18n';

/** Placeholder until the round dashboard (build step 10): proves the shell and the guard work. */
export default function Dashboard() {
    const t = useTranslate();

    return (
        <>
            <Head title={t('dashboard.home.title')} />
            <h1 className="text-2xl font-semibold">{t('dashboard.home.title')}</h1>
            <p className="mt-2 max-w-prose text-ink-2">{t('dashboard.home.placeholder')}</p>
        </>
    );
}
