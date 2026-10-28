import { useEffect, useId, useState, type FormEvent } from 'react';
import { getJson } from '@/lib/api';
import { useTranslate } from '@/lib/i18n';
import type { CorrectableField, DataIssueRow } from '@/types/admin';

export type CorrectIssueFormProps = {
    row: DataIssueRow;
    lgas: { code: string; name: string }[];
    /** The changed fields only; the page then asks for the reason (ConfirmWithReasonDialog). */
    onReview: (corrections: Partial<Record<CorrectableField, string | number | null>>) => void;
    onCancel: () => void;
};

type FacilityOption = { code: string; name: string; ward: string | null };

/**
 * Correct & reprocess (ARCHITECTURE.md §6, D-57): ONLY the fields CorrectAndReprocess allows,
 * each showing what was sent. The LGA is one of the 23; the facility is searched among the
 * chosen LGA's active facilities (a search box and a native list, so it works with the
 * keyboard and a screen reader without a custom combobox). Only changed fields are sent; the
 * server checks every one again.
 */
export default function CorrectIssueForm({ row, lgas, onReview, onCancel }: CorrectIssueFormProps) {
    const t = useTranslate();
    const id = useId();
    const current = row.current;
    const asText = (value: string | number | null) => (value === null || value === '' ? '' : String(value));
    const lgaCode = (value: string | number | null) => lgas.find((lga) => lga.code.toLowerCase() === asText(value).trim().toLowerCase() || lga.name.toLowerCase() === asText(value).trim().toLowerCase())?.code ?? '';

    const initial = {
        lga_ref: lgaCode(current.lga_ref),
        ward_ref: asText(current.ward_ref),
        facility_ref: asText(current.facility_ref),
        raw_start: asText(current.raw_start),
        raw_end: asText(current.raw_end),
        round_year: asText(current.round_year),
        round_quarter: asText(current.round_quarter),
    };
    const [values, setValues] = useState(initial);
    const [search, setSearch] = useState('');
    const [options, setOptions] = useState<FacilityOption[]>([]);
    const [nothing, setNothing] = useState(false);
    const set = (field: CorrectableField, value: string) => {
        setNothing(false);
        setValues((previous) => ({ ...previous, [field]: value }));
    };

    // Active facilities of the chosen LGA matching the search, a moment after typing stops.
    useEffect(() => {
        const controller = new AbortController();
        const timer = setTimeout(() => {
            const query = new URLSearchParams({ ...(values.lga_ref === '' ? {} : { lga: values.lga_ref }), ...(search === '' ? {} : { q: search }) });
            getJson<{ data: FacilityOption[] }>(`/api/facility-options?${query.toString()}`, controller.signal)
                .then((response) => setOptions(response.data))
                .catch(() => undefined);
        }, 250);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [values.lga_ref, search]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const changes: Partial<Record<CorrectableField, string | number | null>> = {};
        (Object.keys(values) as CorrectableField[]).forEach((field) => {
            if (values[field] === initial[field]) {
                return;
            }
            const value = values[field].trim();
            changes[field] = field === 'round_year' || field === 'round_quarter' ? (value === '' ? null : Number(value)) : value === '' ? null : value;
        });
        if (Object.keys(changes).length === 0) {
            setNothing(true);

            return;
        }
        onReview(changes);
    };

    const field = 'flex flex-col gap-1';
    const input = 'min-h-11 rounded-md border border-rule bg-panel px-2.5 text-[13.5px]';
    const hint = (name: CorrectableField) => (
        <span id={`${id}-${name}-sent`} className="text-[12px] text-ink-2">
            {t('admin.issues.correct.as_sent', { value: asText(current[name]) === '' ? t('admin.issues.correct.empty') : asText(current[name]) })}
        </span>
    );
    const label = (name: CorrectableField) => t(`admin.issues.correct.fields.${name}`);

    return (
        <form onSubmit={submit} className="flex flex-col gap-3 rounded-md border border-rule bg-paper p-4" aria-labelledby={`${id}-title`} noValidate>
            <h3 id={`${id}-title`} className="text-[14.5px]">
                {t('admin.issues.correct.title', { instance: row.instance_id })}
            </h3>
            <p className="text-[12.5px] text-ink-2">{t('admin.issues.correct.intro')}</p>
            <div className="grid gap-3 rail:grid-cols-2">
                <div className={field}>
                    <label htmlFor={`${id}-lga`} className="text-[13px] font-medium">
                        {label('lga_ref')}
                    </label>
                    <select id={`${id}-lga`} aria-describedby={`${id}-lga_ref-sent`} className={input} value={values.lga_ref} onChange={(event) => set('lga_ref', event.target.value)}>
                        <option value="">{t('admin.issues.correct.lga_none')}</option>
                        {lgas.map((lga) => (
                            <option key={lga.code} value={lga.code}>
                                {lga.name}
                            </option>
                        ))}
                    </select>
                    {hint('lga_ref')}
                </div>
                <div className={field}>
                    <label htmlFor={`${id}-ward`} className="text-[13px] font-medium">
                        {label('ward_ref')}
                    </label>
                    <input id={`${id}-ward`} aria-describedby={`${id}-ward_ref-sent`} className={input} value={values.ward_ref} onChange={(event) => set('ward_ref', event.target.value)} />
                    {hint('ward_ref')}
                </div>
                <fieldset className="flex flex-col gap-1 rail:col-span-2">
                    <legend className="mb-1 text-[13px] font-medium">{label('facility_ref')}</legend>
                    <label htmlFor={`${id}-facility-search`} className="text-[12.5px]">
                        {t('admin.issues.correct.facility_search')}
                    </label>
                    <input id={`${id}-facility-search`} type="search" className={input} value={search} onChange={(event) => setSearch(event.target.value)} />
                    <label htmlFor={`${id}-facility`} className="mt-1 text-[12.5px]">
                        {t('admin.issues.correct.facility_pick')}
                    </label>
                    <select id={`${id}-facility`} aria-describedby={`${id}-facility_ref-sent`} className={input} value={values.facility_ref} onChange={(event) => set('facility_ref', event.target.value)}>
                        <option value={initial.facility_ref}>{t('admin.issues.correct.facility_keep', { value: initial.facility_ref === '' ? t('admin.issues.correct.empty') : initial.facility_ref })}</option>
                        {options
                            .filter((option) => option.code !== initial.facility_ref)
                            .map((option) => (
                                <option key={option.code} value={option.code}>
                                    {option.name} ({option.code}){option.ward === null ? '' : `, ${option.ward}`}
                                </option>
                            ))}
                    </select>
                    {options.length === 0 && <span className="text-[12px] text-ink-2">{t('admin.issues.correct.facility_none')}</span>}
                    {hint('facility_ref')}
                </fieldset>
                {(['raw_start', 'raw_end'] as const).map((name) => (
                    <div key={name} className={field}>
                        <label htmlFor={`${id}-${name}`} className="text-[13px] font-medium">
                            {label(name)}
                        </label>
                        <input id={`${id}-${name}`} aria-describedby={`${id}-${name}-sent`} className={input} value={values[name]} onChange={(event) => set(name, event.target.value)} />
                        {hint(name)}
                    </div>
                ))}
                <div className={field}>
                    <label htmlFor={`${id}-year`} className="text-[13px] font-medium">
                        {label('round_year')}
                    </label>
                    <input id={`${id}-year`} inputMode="numeric" aria-describedby={`${id}-round_year-sent`} className={input} value={values.round_year} onChange={(event) => set('round_year', event.target.value)} />
                    {hint('round_year')}
                </div>
                <div className={field}>
                    <label htmlFor={`${id}-quarter`} className="text-[13px] font-medium">
                        {label('round_quarter')}
                    </label>
                    <select id={`${id}-quarter`} aria-describedby={`${id}-round_quarter-sent`} className={input} value={values.round_quarter} onChange={(event) => set('round_quarter', event.target.value)}>
                        <option value="">{t('admin.issues.correct.quarter_none')}</option>
                        {['1', '2', '3', '4'].map((quarter) => (
                            <option key={quarter} value={quarter}>
                                Q{quarter}
                            </option>
                        ))}
                    </select>
                    {hint('round_quarter')}
                </div>
            </div>
            {nothing && (
                <p role="alert" className="text-[13px] text-bad">
                    {t('admin.issues.correct.no_changes')}
                </p>
            )}
            <div className="flex flex-wrap gap-2">
                <button type="submit" className="min-h-11 rounded-md bg-deep px-4 text-[13.5px] font-medium text-panel">
                    {t('admin.issues.correct.review')}
                </button>
                <button type="button" onClick={onCancel} className="min-h-11 rounded-md border border-rule bg-panel px-4 text-[13.5px]">
                    {t('admin.issues.correct.cancel')}
                </button>
            </div>
        </form>
    );
}
