import { Form, Head, Link } from '@inertiajs/react';
import ErrorSummary from '@/components/ErrorSummary';
import SubmitButton from '@/components/SubmitButton';
import TextField from '@/components/TextField';
import { useTranslate } from '@/lib/i18n';
import { login } from '@/routes';
import { email } from '@/routes/password';

export type ForgotPasswordProps = {
    status?: string | null | undefined;
};

export default function ForgotPassword({ status }: ForgotPasswordProps) {
    const t = useTranslate();

    return (
        <>
            <Head title={t('auth.forgot.title')} />
            <h1 className="text-2xl font-semibold">{t('auth.forgot.title')}</h1>
            <p className="mt-1 mb-6 text-ink-2">{t('auth.forgot.intro')}</p>

            {status && (
                <p role="status" className="mb-6 rounded-md bg-ok-bg p-3 text-ok">
                    {status}
                </p>
            )}

            <Form {...email.form()} className="space-y-5">
                {({ processing, errors }) => (
                    <>
                        <ErrorSummary errors={errors} />
                        <TextField
                            name="email"
                            type="email"
                            label={t('auth.fields.email')}
                            autoComplete="username"
                            required
                            error={errors.email}
                        />
                        <SubmitButton processing={processing}>{t('auth.forgot.submit')}</SubmitButton>
                    </>
                )}
            </Form>

            <p className="mt-5 text-center">
                <Link href={login()} className="text-deep underline underline-offset-2">
                    {t('auth.forgot.back')}
                </Link>
            </p>
        </>
    );
}
