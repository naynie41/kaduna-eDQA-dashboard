import { Form, Head, Link } from '@inertiajs/react';
import ErrorSummary from '@/components/ErrorSummary';
import SubmitButton from '@/components/SubmitButton';
import TextField from '@/components/TextField';
import { useTranslate } from '@/lib/i18n';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

export type LoginProps = {
    status?: string | null | undefined;
};

export default function Login({ status }: LoginProps) {
    const t = useTranslate();

    return (
        <>
            <Head title={t('auth.login.title')} />
            <h1 className="text-2xl font-semibold">{t('auth.login.title')}</h1>
            <p className="mt-1 mb-6 text-ink-2">{t('auth.login.intro')}</p>

            {status && (
                <p role="status" className="mb-6 rounded-md bg-ok-bg p-3 text-ok">
                    {status}
                </p>
            )}

            <Form {...store.form()} resetOnSuccess={['password']} className="space-y-5">
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
                        <TextField
                            name="password"
                            type="password"
                            label={t('auth.fields.password')}
                            autoComplete="current-password"
                            required
                            error={errors.password}
                        />
                        <div className="flex items-center gap-2">
                            <input id="remember" name="remember" type="checkbox" className="size-4 accent-deep" />
                            <label htmlFor="remember">{t('auth.fields.remember')}</label>
                        </div>
                        <SubmitButton processing={processing}>{t('auth.login.submit')}</SubmitButton>
                        <p className="text-center">
                            <Link href={request()} className="text-deep underline underline-offset-2">
                                {t('auth.login.forgot')}
                            </Link>
                        </p>
                    </>
                )}
            </Form>
        </>
    );
}
