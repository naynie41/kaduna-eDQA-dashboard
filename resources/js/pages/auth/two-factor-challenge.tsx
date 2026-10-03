import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import ErrorSummary from '@/components/ErrorSummary';
import SubmitButton from '@/components/SubmitButton';
import TextField from '@/components/TextField';
import { useTranslate } from '@/lib/i18n';
import { store } from '@/routes/two-factor/login';

export default function TwoFactorChallenge() {
    const t = useTranslate();
    const [useRecovery, setUseRecovery] = useState(false);

    return (
        <>
            <Head title={t('auth.two_factor.challenge.title')} />
            <h1 className="text-2xl font-semibold">{t('auth.two_factor.challenge.title')}</h1>
            <p className="mt-1 mb-6 text-ink-2">
                {useRecovery ? t('auth.two_factor.challenge.intro_recovery') : t('auth.two_factor.challenge.intro_code')}
            </p>

            <Form {...store.form()} resetOnError className="space-y-5">
                {({ processing, errors }) => (
                    <>
                        <ErrorSummary errors={errors} />
                        {useRecovery ? (
                            <TextField
                                key="recovery_code"
                                name="recovery_code"
                                label={t('auth.fields.recovery_code')}
                                autoComplete="off"
                                required
                                error={errors.recovery_code}
                            />
                        ) : (
                            <TextField
                                key="code"
                                name="code"
                                label={t('auth.fields.code')}
                                autoComplete="one-time-code"
                                inputMode="numeric"
                                pattern="[0-9]*"
                                maxLength={6}
                                required
                                error={errors.code}
                            />
                        )}
                        <SubmitButton processing={processing}>{t('auth.two_factor.challenge.submit')}</SubmitButton>
                    </>
                )}
            </Form>

            <p className="mt-5 text-center">
                <button
                    type="button"
                    onClick={() => setUseRecovery((value) => !value)}
                    className="text-deep underline underline-offset-2"
                >
                    {useRecovery ? t('auth.two_factor.challenge.use_code') : t('auth.two_factor.challenge.use_recovery')}
                </button>
            </p>
        </>
    );
}
