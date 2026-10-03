import type { Translations } from '@/lib/i18n';
import type { Auth } from '@/types/auth';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            translations: Translations;
            [key: string]: unknown;
        };
    }
}
