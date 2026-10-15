import type { ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import Announcer from '@/components/Announcer';
import { I18nProvider } from '@/lib/i18n';

export type PageProvidersProps = {
    children: ReactNode;
};

/**
 * What every page sits in: the shared translations (read by useTranslate) and the single polite
 * live region (useAnnounce). Each layout wraps its whole tree in this, so the layout's own
 * strings and the page's come from the same place.
 */
export default function PageProviders({ children }: PageProvidersProps) {
    const { translations } = usePage().props;

    return (
        <I18nProvider translations={translations}>
            <Announcer>{children}</Announcer>
        </I18nProvider>
    );
}
