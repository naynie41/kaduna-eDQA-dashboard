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
    /**
     * Shown but not usable; describedBy should point at the text saying why. aria-disabled, not
     * disabled: the buttons stay in the tab order, so keyboard and screen-reader users reach the
     * reason too.
     */
    disabled?: boolean;
    /** The id of text that explains the control (why it is disabled). */
    describedBy?: string;
};

/**
 * A row of toggle buttons, one pressed: a labelled group with aria-pressed (not radios or tabs).
 * The dividers are the 1px gaps showing the rule colour, so the row can wrap at 360px.
 */
export default function SegmentedControl<T extends string>({ label, options, value, onChange, disabled = false, describedBy }: SegmentedControlProps<T>) {
    return (
        <div role="group" aria-label={label} aria-describedby={describedBy} className="inline-flex max-w-full flex-wrap gap-px overflow-hidden rounded-[7px] border border-rule bg-rule">
            {options.map((option) => {
                const pressed = option.value === value;
                const tone = pressed ? 'bg-deep font-medium text-panel' : disabled ? 'bg-paper text-ink-2' : 'bg-panel text-ink-2 hover:text-ink';

                return (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={pressed}
                        aria-disabled={disabled || undefined}
                        onClick={() => {
                            if (!disabled) {
                                onChange(option.value);
                            }
                        }}
                        className={`grow px-3.5 py-1.5 text-[13px] ${tone} ${disabled ? 'cursor-not-allowed' : ''}`}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
