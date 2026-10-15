import type { Translations } from '@/lib/i18n';
import type { Auth } from '@/types/auth';
import type { FilterOptions, Filters, ShellStatus, Summary } from '@/types/shell';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            translations: Translations;
            /** Null for a guest. */
            shell: ShellStatus | null;
            /** Dashboard pages only (ShareDashboardContext). */
            filters?: Filters;
            filterOptions?: FilterOptions;
            summary?: Summary;
            [key: string]: unknown;
        };
    }
}
