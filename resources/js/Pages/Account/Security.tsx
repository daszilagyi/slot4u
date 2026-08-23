import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';

import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';

type SecurityProps = {
    twoFactor: {
        enabled: boolean;
        pending: boolean;
        /** Fortify's own QR, rendered server-side; null unless setup is pending. */
        qrSvg: string | null;
        /** The same secret in text, for an authenticator that cannot scan. */
        secret: string | null;
        recoveryCodes: string[];
    };
    /** True for a superadmin, who may not switch it off. */
    required: boolean;
    backUrl: string;
};

/**
 * The signed-in person's own account security (SLO-149).
 *
 * Three states, and the middle one is the reason the page is not a single
 * toggle: `pending` is a setup somebody started and did not finish — a closed
 * tab halfway through scanning the QR. Treating that as "off" would leave a
 * secret on the account that nothing can reach; the page offers the way through
 * it instead.
 *
 * ⚠️ The whole page sits behind password confirmation, which is what makes it
 * safe to print the recovery codes here at all.
 */
export default function Security({ twoFactor, required, backUrl }: SecurityProps) {
    const t = useTranslations();
    const { status } = usePage().props;
    const confirm = useForm({ code: '' });

    function enable() {
        router.post('/user/two-factor-authentication', {}, { preserveScroll: true });
    }

    function disable() {
        router.delete('/user/two-factor-authentication', { preserveScroll: true });
    }

    function regenerate() {
        router.post('/user/two-factor-recovery-codes', {}, { preserveScroll: true });
    }

    function confirmCode(event: FormEvent) {
        event.preventDefault();
        confirm.post('/user/confirmed-two-factor-authentication', {
            preserveScroll: true,
            onSuccess: () => confirm.reset('code'),
        });
    }

    return (
        <AppLayout>
            <Head title={t('security.title')} />

            <div className="flex w-full max-w-xl flex-col gap-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('security.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('security.subtitle')}
                    </p>
                </div>

                {status ? (
                    <p className="rounded-lg border border-amber-500/40 bg-amber-500/5 px-4 py-3 text-sm text-amber-400">
                        {status}
                    </p>
                ) : null}

                <section className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5">
                    <div className="flex flex-col gap-1">
                        <h2 className="text-base font-medium">
                            {t('security.two_factor.title')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {required
                                ? t('security.two_factor.required_hint')
                                : t('security.two_factor.optional_hint')}
                        </p>
                    </div>

                    {/* --- Off --- */}
                    {!twoFactor.enabled && !twoFactor.pending ? (
                        <Button onClick={enable}>
                            {t('security.two_factor.enable')}
                        </Button>
                    ) : null}

                    {/* --- Started, not finished --- */}
                    {twoFactor.pending ? (
                        <div className="flex flex-col gap-4">
                            <p className="text-sm">
                                {t('security.two_factor.scan')}
                            </p>

                            {/* Fortify returns a complete <svg>; it is generated
                                on our own server from our own secret, never from
                                remote input. */}
                            {twoFactor.qrSvg ? (
                                <div
                                    className="w-fit rounded-lg bg-white p-3"
                                    dangerouslySetInnerHTML={{
                                        __html: twoFactor.qrSvg,
                                    }}
                                />
                            ) : null}

                            <p className="text-xs text-muted-foreground">
                                {t('security.two_factor.manual_key')}
                                <code className="ml-1 select-all font-mono">
                                    {twoFactor.secret}
                                </code>
                            </p>

                            <form
                                onSubmit={confirmCode}
                                className="flex flex-col gap-2"
                            >
                                <Label htmlFor="code">
                                    {t('security.two_factor.confirm_label')}
                                </Label>
                                <Input
                                    id="code"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    value={confirm.data.code}
                                    onChange={(e) =>
                                        confirm.setData('code', e.target.value)
                                    }
                                />
                                {confirm.errors.code ? (
                                    <p className="text-sm text-destructive">
                                        {confirm.errors.code}
                                    </p>
                                ) : null}
                                <div className="flex gap-2">
                                    <Button
                                        type="submit"
                                        disabled={confirm.processing}
                                    >
                                        {t('security.two_factor.confirm')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={disable}
                                    >
                                        {t('security.two_factor.cancel_setup')}
                                    </Button>
                                </div>
                            </form>
                        </div>
                    ) : null}

                    {/* --- On --- */}
                    {twoFactor.enabled ? (
                        <div className="flex flex-col gap-4">
                            <p className="text-sm text-primary">
                                {t('security.two_factor.enabled')}
                            </p>

                            <div className="flex flex-col gap-2">
                                <p className="text-sm font-medium">
                                    {t('security.two_factor.recovery_title')}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {t('security.two_factor.recovery_hint')}
                                </p>
                                <ul className="grid grid-cols-2 gap-1 rounded-lg border border-border bg-background p-3 font-mono text-xs">
                                    {twoFactor.recoveryCodes.map((code) => (
                                        <li key={code} className="select-all">
                                            {code}
                                        </li>
                                    ))}
                                </ul>
                            </div>

                            <div className="flex flex-wrap gap-2">
                                <Button variant="outline" onClick={regenerate}>
                                    {t('security.two_factor.regenerate')}
                                </Button>
                                {/* A superadmin gets no disable button at all,
                                    rather than one that always fails (SLO-149). */}
                                {required ? null : (
                                    <Button
                                        variant="destructive"
                                        onClick={disable}
                                    >
                                        {t('security.two_factor.disable')}
                                    </Button>
                                )}
                            </div>
                        </div>
                    ) : null}
                </section>

                <Link
                    href={backUrl}
                    className="text-sm text-muted-foreground underline underline-offset-2 hover:text-foreground"
                >
                    {t('security.back')}
                </Link>
            </div>
        </AppLayout>
    );
}
