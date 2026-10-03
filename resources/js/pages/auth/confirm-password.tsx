import { Form, Head } from '@inertiajs/react';
import ErrorSummary from '@/components/ErrorSummary';
import SubmitButton from '@/components/SubmitButton';
import TextField from '@/components/TextField';
import { useTranslate } from '@/lib/i18n';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    const t = useTranslate();

    return (
        <>
            <Head title={t('auth.confirm_password.title')} />
            <h1 className="text-2xl font-semibold">{t('auth.confirm_password.title')}</h1>
            <p className="mt-1 mb-6 text-ink-2">{t('auth.confirm_password.intro')}</p>

            <Form {...store.form()} resetOnSuccess={['password']} className="space-y-5">
                {({ processing, errors }) => (
                    <>
                        <ErrorSummary errors={errors} />
                        <TextField
                            name="password"
                            type="password"
                            label={t('auth.fields.password')}
                            autoComplete="current-password"
                            required
                            error={errors.password}
                        />
                        <SubmitButton processing={processing}>{t('auth.confirm_password.submit')}</SubmitButton>
                    </>
                )}
            </Form>
        </>
    );
}
