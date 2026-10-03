import { Form, Head } from '@inertiajs/react';
import ErrorSummary from '@/components/ErrorSummary';
import SubmitButton from '@/components/SubmitButton';
import TextField from '@/components/TextField';
import { useTranslate } from '@/lib/i18n';
import { update } from '@/routes/password';

export type ResetPasswordProps = {
    token: string;
    email: string;
};

export default function ResetPassword({ token, email }: ResetPasswordProps) {
    const t = useTranslate();

    return (
        <>
            <Head title={t('auth.reset.title')} />
            <h1 className="mb-6 text-2xl font-semibold">{t('auth.reset.title')}</h1>

            <Form {...update.form()} transform={(data) => ({ ...data, token })} resetOnSuccess={['password', 'password_confirmation']} className="space-y-5">
                {({ processing, errors }) => (
                    <>
                        <ErrorSummary errors={errors} />
                        <TextField
                            name="email"
                            type="email"
                            label={t('auth.fields.email')}
                            autoComplete="username"
                            defaultValue={email}
                            readOnly
                            error={errors.email}
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
                        <SubmitButton processing={processing}>{t('auth.reset.submit')}</SubmitButton>
                    </>
                )}
            </Form>
        </>
    );
}
