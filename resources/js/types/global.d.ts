import type { Translations } from '@/lib/i18n';
import type { Auth } from '@/types/auth';
import type { AdminShell, FilterOptions, Filters, ShellStatus, Summary } from '@/types/shell';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            translations: Translations;
            /** The admin layout's status bar and count: admin pages only, never a public page. */
            shell?: ShellStatus;
            /** The admin pages' badge and stale-ingestion banner: admin pages only. */
            admin?: AdminShell;
            /** Dashboard pages only (ShareDashboardContext). */
            filters?: Filters;
            filterOptions?: FilterOptions;
            summary?: Summary;
            /** A visitor's dashboard page while no round is closed yet (D-77). */
            unpublished?: boolean;
            /** The public footer's "Figures last updated" (D-81); null while nothing is published. */
            figuresUpdatedAt?: string | null;
            [key: string]: unknown;
        };
    }
}
