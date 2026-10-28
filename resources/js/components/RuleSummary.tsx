import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { RuleSummary as Rule } from '@/types/admin';

export type RuleSummaryProps = {
    rules: Rule[];
    /** The rule the table is filtered by. */
    active: string | null;
    /** Filter by this rule, or by none. */
    onChoose: (code: string | null) => void;
};

/**
 * Every validation rule (the 14 and RULE_ERROR) with its severity, name, why it exists and how
 * many records it holds open (D-57). Each is a toggle button that filters the table by it; the
 * severity is a word and a shape, never only a colour.
 */
export default function RuleSummary({ rules, active, onChoose }: RuleSummaryProps) {
    const t = useTranslate();

    return (
        <Panel labelledBy="issues-rules-title">
            <PanelHeader id="issues-rules-title" title={t('admin.issues.summary.title')} description={t('admin.issues.summary.description')} />
            <ul className="grid gap-2 px-5 pb-5 rail:grid-cols-2">
                {rules.map((rule) => {
                    const pressed = active === rule.code;
                    // ink-2 on the pressed wash is 4.3:1, under AA: full ink when pressed.
                    const secondary = pressed ? 'text-ink' : 'text-ink-2';

                    return (
                        <li key={rule.code}>
                            <button
                                type="button"
                                aria-pressed={pressed}
                                onClick={() => onChoose(pressed ? null : rule.code)}
                                className={`flex w-full flex-col gap-1 rounded-md border px-3 py-2.5 text-left text-[13px] ${
                                    pressed ? 'border-deep bg-wash forced-colors:border-[Highlight]' : 'border-rule bg-panel hover:bg-paper'
                                }`}
                            >
                                <span className="flex flex-wrap items-center gap-2">
                                    <SeverityTag severity={rule.severity} label={t(`admin.issues.summary.${rule.severity}`)} />
                                    <span className="font-medium">{rule.name}</span>
                                    <code className={`text-[11.5px] ${secondary}`}>{rule.code}</code>
                                    <span className={`ml-auto rounded-full px-2 py-px text-[12px] tabular-nums ${rule.open > 0 ? 'bg-bad-bg text-bad' : 'bg-paper text-ink-2'}`}>
                                        {t('admin.issues.summary.open', { count: formatCount(rule.open) })}
                                    </span>
                                </span>
                                <span className={`text-[12.5px] ${secondary}`}>{rule.why}</span>
                            </button>
                        </li>
                    );
                })}
            </ul>
        </Panel>
    );
}

export function SeverityTag({ severity, label }: { severity: string; label: string }) {
    const hard = severity === 'hard';

    return (
        <span className={`inline-flex items-center gap-1 rounded-full px-1.5 py-px text-[11px] whitespace-nowrap ${hard ? 'bg-bad-bg text-bad' : 'bg-warn-bg text-warn'}`}>
            <span aria-hidden="true" className="text-[9px]">
                {hard ? '■' : '▲'}
            </span>
            {label}
        </span>
    );
}
