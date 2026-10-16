export type SegmentedOption<T extends string> = {
    value: T;
    label: string;
};

export type SegmentedControlProps<T extends string> = {
    /** Names the group, e.g. "Metric". */
    label: string;
    options: readonly SegmentedOption<T>[];
    value: T;
    onChange: (value: T) => void;
};

/**
 * A row of toggle buttons, one pressed: a labelled group with aria-pressed (not radios or tabs).
 * The dividers are the 1px gaps showing the rule colour, so the row can wrap at 360px.
 */
export default function SegmentedControl<T extends string>({ label, options, value, onChange }: SegmentedControlProps<T>) {
    return (
        <div role="group" aria-label={label} className="inline-flex max-w-full flex-wrap gap-px overflow-hidden rounded-[7px] border border-rule bg-rule">
            {options.map((option) => {
                const pressed = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={pressed}
                        onClick={() => onChange(option.value)}
                        className={`grow px-3.5 py-1.5 text-[13px] ${
                            pressed ? 'bg-deep font-medium text-panel' : 'bg-panel text-ink-2 hover:text-ink'
                        }`}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
