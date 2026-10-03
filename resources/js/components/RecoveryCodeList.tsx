import { useTranslate } from '@/lib/i18n';

export type RecoveryCodeListProps = {
    codes: string[];
};

/** Recovery codes, shown once (SECURITY.md §2): monospace, easy to copy as a block. */
export default function RecoveryCodeList({ codes }: RecoveryCodeListProps) {
    const t = useTranslate();

    return (
        <ul aria-label={t('auth.two_factor.setup.codes_list')} className="grid gap-2 rounded-md bg-wash p-4 font-mono text-ink sm:grid-cols-2">
            {codes.map((code) => (
                <li key={code}>{code}</li>
            ))}
        </ul>
    );
}
