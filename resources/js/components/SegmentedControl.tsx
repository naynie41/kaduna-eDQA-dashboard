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
        <div role="group" aria-label={label} aria-describedby={describedBy} className="inline-flex max-w-full flex-wrap gap-0.5 overflow-hidden rounded-md bg-paper p-0.5 ring-1 ring-rule ring-inset">
            {options.map((option) => {
                const pressed = option.value === value;
                const tone = pressed
                    ? 'bg-panel font-semibold text-deep shadow-[0_1px_2px_rgba(13,40,36,0.12)] ring-1 ring-control ring-inset forced-colors:forced-color-adjust-none forced-colors:bg-[Highlight] forced-colors:text-[HighlightText]'
                    : disabled
                      ? 'text-ink-2'
                      : 'text-ink-2 hover:bg-panel/70 hover:text-ink';

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
                        className={`grow rounded-[4px] px-3 py-1.5 text-sm transition-colors duration-150 ${tone} ${disabled ? 'cursor-not-allowed' : ''}`}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
