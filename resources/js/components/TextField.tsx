export type TextFieldProps = {
    /** Also the input's id, so the error summary can link to it. */
    name: string;
    label: string;
    type?: 'text' | 'email' | 'password' | undefined;
    /** Required: say what the field is, so browsers and password managers fill it correctly. */
    autoComplete: string;
    inputMode?: 'text' | 'numeric' | undefined;
    error?: string | undefined;
    hint?: string | undefined;
    required?: boolean | undefined;
    defaultValue?: string | undefined;
    readOnly?: boolean | undefined;
    maxLength?: number | undefined;
    pattern?: string | undefined;
};

/**
 * A labelled input with its hint and error tied to it (aria-describedby), the error repeated
 * next to the field as well as in the summary.
 */
export default function TextField({ name, label, type = 'text', error, hint, ...input }: TextFieldProps) {
    const describedBy = [hint ? `${name}-hint` : null, error ? `${name}-error` : null].filter(Boolean).join(' ');

    return (
        <div className="space-y-1.5">
            <label htmlFor={name} className="block font-medium text-ink">
                {label}
            </label>
            {hint && (
                <p id={`${name}-hint`} className="text-sm text-ink-2">
                    {hint}
                </p>
            )}
            {error && (
                <p id={`${name}-error`} className="text-sm font-medium text-bad">
                    {error}
                </p>
            )}
            <input
                id={name}
                name={name}
                type={type}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy || undefined}
                className={`block w-full rounded-md border bg-panel px-3 py-2 text-ink ${error ? 'border-2 border-bad' : 'border-ink-2'}`}
                {...input}
            />
        </div>
    );
}
