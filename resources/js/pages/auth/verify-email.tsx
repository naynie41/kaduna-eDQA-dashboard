import { Form, Head, Link } from '@inertiajs/react';
import { useTranslate } from '@/lib/i18n';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export type VerifyEmailProps = {
    status?: string | null | undefined;
};

export default function VerifyEmail({ status }: VerifyEmailProps) {
    const t = useTranslate();

    return (
        <>
            <Head title={t('auth.verify_email.title')} />
            <h1 className="text-2xl font-semibold">{t('auth.verify_email.title')}</h1>
            <p className="mt-1 mb-6 text-ink-2">{t('auth.verify_email.intro')}</p>

            {status === 'verification-link-sent' && (
                <p role="status" className="mb-6 rounded-md bg-ok-bg p-3 text-ok">
                    {t('auth.verify_email.sent')}
                </p>
            )}

            <Form {...send.form()} className="flex flex-wrap items-center justify-between gap-4">
                {({ processing }) => (
                    <>
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-md bg-deep px-4 py-2.5 font-semibold text-panel hover:bg-deep-2"
                        >
                            {t('auth.verify_email.resend')}
                        </button>
                        <Link href={logout()} as="button" className="text-deep underline underline-offset-2">
                            {t('common.user_menu.log_out')}
                        </Link>
                    </>
                )}
            </Form>
        </>
    );
}
