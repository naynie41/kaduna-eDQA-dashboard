import { useId, useState } from 'react';
import Labelled from '@/components/Labelled';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { CorrectableField } from '@/types/admin';

const FIELDS: CorrectableField[] = ['lga_ref', 'ward_ref', 'facility_ref', 'raw_start', 'raw_end', 'round_year', 'round_quarter'];

export type IssueBulkBarProps = {
    /** How many records are ticked. */
    count: number;
    /** Whether every ticked record fails the same single rule (bulkRule): otherwise bulk is off. */
    allowed: boolean;
    onReject: () => void;
    /** One field set to one value on every ticked record; numbers for the round fields. */
    onCorrect: (field: CorrectableField, corrections: Partial<Record<CorrectableField, string | number | null>>) => void;
    onClear: () => void;
};

/** The Data issues page's bulk bar (D-57): reject or correct every ticked record at once. */
export default function IssueBulkBar({ count, allowed, onReject, onCorrect, onClear }: IssueBulkBarProps) {
    const t = useTranslate();
    const ids = { field: useId(), value: useId(), mixed: useId() };
    const [field, setField] = useState<CorrectableField>('lga_ref');
    const [value, setValue] = useState('');
    const select = 'control min-h-11';
    const button = 'btn btn-secondary min-h-11';
    const describedBy = allowed ? undefined : ids.mixed;
    const numeric = field === 'round_year' || field === 'round_quarter';

    return (
        <div role="group" aria-label={t('admin.issues.bulk.label')} className="mx-5 mb-3 flex flex-wrap items-end gap-3 rounded-md border border-rule bg-paper px-3 py-2.5 text-sm">
            <p className="self-center font-medium">{t('admin.issues.bulk.selected', { count: formatCount(count) })}</p>
            {!allowed && (
                <p id={ids.mixed} className="self-center text-bad">
                    {t('admin.issues.bulk.mixed')}
                </p>
            )}
            <button type="button" className={button} disabled={!allowed} aria-describedby={describedBy} onClick={onReject}>
                {t('admin.issues.bulk.reject')}
            </button>
            <Labelled id={ids.field} label={t('admin.issues.bulk.field')}>
                <select id={ids.field} className={select} value={field} onChange={(event) => setField(event.target.value as CorrectableField)} disabled={!allowed}>
                    {FIELDS.map((option) => (
                        <option key={option} value={option}>
                            {t(`admin.issues.correct.fields.${option}`)}
                        </option>
                    ))}
                </select>
            </Labelled>
            <Labelled id={ids.value} label={t('admin.issues.bulk.value')}>
                <input id={ids.value} className={select} value={value} onChange={(event) => setValue(event.target.value)} disabled={!allowed} />
            </Labelled>
            <button
                type="button"
                className={button}
                disabled={!allowed}
                aria-describedby={describedBy}
                onClick={() => onCorrect(field, { [field]: value === '' ? null : numeric ? Number(value) : value })}
            >
                {t('admin.issues.bulk.correct')}
            </button>
            <button type="button" className={button} onClick={onClear}>
                {t('admin.issues.bulk.clear')}
            </button>
        </div>
    );
}
