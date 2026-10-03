import { Form, Head, Link } from '@inertiajs/react';
import ErrorSummary from '@/components/ErrorSummary';
import RecoveryCodeList from '@/components/RecoveryCodeList';
import SubmitButton from '@/components/SubmitButton';
import TextField from '@/components/TextField';
import { useTranslate } from '@/lib/i18n';
import { regenerateRecoveryCodes, setup } from '@/routes/two-factor';
import { update } from '@/routes/user-password';

export type SecurityProps = {
    status?: string | null | undefined;
    twoFactorEnabled: boolean;
    recoveryCodes: string[] | null;
};

export default function Security({ status, twoFactorEnabled, recoveryCodes }: SecurityProps) {
    const t = useTranslate();

    return (
        <>
            <Head title={t('auth.settings.title')} />
            <h1 className="mb-6 text-2xl font-semibold">{t('auth.settings.title')}</h1>

            <div className="grid max-w-xl gap-8">
                <section aria-labelledby="password-title" className="rounded-[var(--radius-panel)] bg-panel p-6 shadow-[var(--shadow-panel)]">
                    <h2 id="password-title" className="mb-4 text-lg font-semibold">
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

                <section aria-labelledby="two-factor-title" className="rounded-[var(--radius-panel)] bg-panel p-6 shadow-[var(--shadow-panel)]">
                    <h2 id="two-factor-title" className="mb-2 text-lg font-semibold">
                        {t('auth.settings.two_factor_title')}
                    </h2>
                    {!twoFactorEnabled ? (
                        <>
                            <p className="mb-4 text-ink-2">{t('auth.settings.two_factor_off')}</p>
                            <Link href={setup()} className="text-deep underline underline-offset-2">
                                {t('auth.settings.two_factor_set_up')}
                            </Link>
                        </>
                    ) : recoveryCodes ? (
                        <>
                            <p className="mb-3 font-medium">{t('auth.two_factor.setup.codes_intro')}</p>
                            <RecoveryCodeList codes={recoveryCodes} />
                        </>
                    ) : (
                        <>
                            <p className="mb-4 text-ink-2">{t('auth.settings.two_factor_on')}</p>
                            <p className="mb-4 text-sm text-ink-2">{t('auth.settings.regenerate_intro')}</p>
                            <Form {...regenerateRecoveryCodes.form()} options={{ preserveScroll: true }}>
                                {({ processing }) => <SubmitButton processing={processing}>{t('auth.settings.regenerate')}</SubmitButton>}
                            </Form>
                        </>
                    )}
                </section>
            </div>
        </>
    );
}
