import { Form, Head, Link } from '@inertiajs/react';
import ErrorSummary from '@/components/ErrorSummary';
import RecoveryCodeList from '@/components/RecoveryCodeList';
import SubmitButton from '@/components/SubmitButton';
import TextField from '@/components/TextField';
import { useTranslate } from '@/lib/i18n';
import { home, logout } from '@/routes';
import { confirm, enable } from '@/routes/two-factor';

export type TwoFactorSetupProps = {
    step: 'start' | 'confirm' | 'recovery-codes';
    qrCodeSvg: string | null;
    setupKey: string | null;
    recoveryCodes: string[] | null;
};

/** Mandatory 2FA setup (SECURITY.md §2): the only page a signed-in user reaches until done. */
export default function TwoFactorSetup({ step, qrCodeSvg, setupKey, recoveryCodes }: TwoFactorSetupProps) {
    const t = useTranslate();

    return (
        <>
            <Head title={t('auth.two_factor.setup.title')} />
            <h1 className="text-2xl font-semibold">
                {step === 'recovery-codes' ? t('auth.two_factor.setup.codes_title') : t('auth.two_factor.setup.title')}
            </h1>

            {step === 'start' && (
                <>
                    <p className="mt-1 mb-6 text-ink-2">{t('auth.two_factor.setup.intro')}</p>
                    <Form {...enable.form()}>
                        {({ processing }) => <SubmitButton processing={processing}>{t('auth.two_factor.setup.start')}</SubmitButton>}
                    </Form>
                </>
            )}

            {step === 'confirm' && (
                <>
                    <p className="mt-1 mb-4 text-ink-2">{t('auth.two_factor.setup.scan')}</p>
                    {qrCodeSvg && (
                        // An <img> never runs script inside the SVG, unlike injected markup.
                        <img
                            src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(qrCodeSvg)}`}
                            alt={t('auth.two_factor.setup.qr_alt')}
                            className="mx-auto mb-4 size-48 rounded-md border border-rule bg-panel p-2"
                        />
                    )}
                    {setupKey && (
                        <p className="mb-6 text-sm text-ink-2">
                            {t('auth.two_factor.setup.manual')}{' '}
                            <code className="rounded bg-wash px-1.5 py-0.5 font-mono text-ink break-all">{setupKey}</code>
                        </p>
                    )}
                    <p className="mb-4 text-ink-2">{t('auth.two_factor.setup.confirm_intro')}</p>
                    <Form {...confirm.form()} errorBag="confirmTwoFactorAuthentication" resetOnError className="space-y-5">
                        {({ processing, errors }) => (
                            <>
                                <ErrorSummary errors={errors} />
                                <TextField
                                    name="code"
                                    label={t('auth.fields.code')}
                                    autoComplete="one-time-code"
                                    inputMode="numeric"
                                    pattern="[0-9]*"
                                    maxLength={6}
                                    required
                                    error={errors.code}
                                />
                                <SubmitButton processing={processing}>{t('auth.two_factor.setup.confirm')}</SubmitButton>
                            </>
                        )}
                    </Form>
                </>
            )}

            {step === 'recovery-codes' && recoveryCodes && (
                <>
                    <p className="mt-1 mb-4 text-ink-2">{t('auth.two_factor.setup.codes_intro')}</p>
                    <RecoveryCodeList codes={recoveryCodes} />
                    <Link
                        href={home()}
                        className="mt-6 inline-flex w-full items-center justify-center rounded-md bg-deep px-4 py-2.5 font-semibold text-panel hover:bg-deep-2"
                    >
                        {t('auth.two_factor.setup.done')}
                    </Link>
                </>
            )}

            <p className="mt-6 text-center">
                <Link href={logout()} as="button" className="text-deep underline underline-offset-2">
                    {t('common.user_menu.log_out')}
                </Link>
            </p>
        </>
    );
}
