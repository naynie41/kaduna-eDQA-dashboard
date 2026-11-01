import { useTranslate } from '@/lib/i18n';

export type SourceTagProps = {
    /** The source as the server names it ("ODK Central", "KoboToolbox"); null when unknown. */
    source: { key: string; name: string } | null;
};

/**
 * Which field-collection tool a record came from, in words (CLAUDE.md hard rule 11: never by colour
 * alone). Every source looks the same: the name is what tells them apart, so nothing is lost to a
 * screen reader, in forced colours or to a colour-blind reader.
 */
export default function SourceTag({ source }: SourceTagProps) {
    const t = useTranslate();

    return (
        <span className="inline-flex items-center rounded-sm bg-paper px-1.5 py-0.5 text-2xs font-medium whitespace-nowrap text-ink ring-1 ring-rule ring-inset">
            {source === null ? t('common.sources.unknown') : source.name}
        </span>
    );
}
