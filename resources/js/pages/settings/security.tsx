import { Form } from '@inertiajs/react';
import ErrorSummary from '@/components/ErrorSummary';
import SubmitButton from '@/components/SubmitButton';
import TextField from '@/components/TextField';
import { useTranslate } from '@/lib/i18n';
import { update } from '@/routes/admin/user-password';

export type SecurityProps = {
    status?: string | null | undefined;
};

export default function Security({ status }: SecurityProps) {
    const t = useTranslate();

    return (
        <>
            {/* The title (h1 and <title>) comes from AppShell. */}
            <div className="grid max-w-xl gap-8">
                <section aria-labelledby="password-title" className="rounded-panel border border-rule bg-panel p-6">
                    <h2 id="password-title" className="mb-4 text-md font-semibold">
                        {t('auth.settings.password_title')}
                    </h2>
                    {status && (
                        <p role="status" className="mb-4 rounded-md bg-ok-bg p-3 text-ok">
                            {status}
                        </p>
                    )}
                    <Form
                        {...update.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        resetOnError={['password', 'password_confirmation', 'current_password']}
                        className="space-y-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <ErrorSummary errors={errors} />
                                <TextField
                                    name="current_password"
                                    type="password"
                                    label={t('auth.fields.current_password')}
                                    autoComplete="current-password"
                                    required
                                    error={errors.current_password}
                                />
                                <TextField
                                    name="password"
                                    type="password"
                                    label={t('auth.fields.new_password')}
                                    hint={t('auth.reset.rules')}
                                    autoComplete="new-password"
                                    required
                                    error={errors.password}
                                />
                                <TextField
                                    name="password_confirmation"
                                    type="password"
                                    label={t('auth.fields.confirm_password')}
                                    autoComplete="new-password"
                                    required
                                    error={errors.password_confirmation}
                                />
                                <SubmitButton processing={processing}>{t('auth.settings.password_submit')}</SubmitButton>
                            </>
                        )}
                    </Form>
                </section>
            </div>
        </>
    );
}
