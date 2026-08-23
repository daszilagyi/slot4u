import { Head, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

import AuthLayout from '@/Layouts/AuthLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';

/**
 * The second step of signing in (SLO-149).
 *
 * Two inputs, one at a time. Fortify decides which it received: `code` is the
 * six digits from the authenticator, `recovery_code` is one of the one-time
 * codes from setup. Sending both would be ambiguous, so the toggle swaps them
 * rather than showing both at once.
 */
export default function TwoFactorChallenge() {
    const t = useTranslations();
    const [useRecovery, setUseRecovery] = useState(false);
    const form = useForm({ code: '', recovery_code: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/two-factor-challenge', {
            // Whichever field is not on screen is cleared, so a half-typed code
            // left behind by the toggle cannot be submitted alongside the other.
            onFinish: () => form.reset(useRecovery ? 'recovery_code' : 'code'),
        });
    }

    function toggle() {
        form.reset('code', 'recovery_code');
        form.clearErrors();
        setUseRecovery(!useRecovery);
    }

    return (
        <AuthLayout
            title={t('auth.two_factor.title')}
            subtitle={
                useRecovery
                    ? t('auth.two_factor.subtitle_recovery')
                    : t('auth.two_factor.subtitle')
            }
        >
            <Head title={t('auth.two_factor.title')} />

            <form onSubmit={submit} className="flex flex-col gap-4">
                {useRecovery ? (
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="recovery_code">
                            {t('auth.two_factor.recovery_code')}
                        </Label>
                        <Input
                            id="recovery_code"
                            name="recovery_code"
                            value={form.data.recovery_code}
                            autoComplete="one-time-code"
                            autoFocus
                            onChange={(e) =>
                                form.setData('recovery_code', e.target.value)
                            }
                        />
                        {form.errors.recovery_code ? (
                            <p className="text-sm text-red-500">
                                {form.errors.recovery_code}
                            </p>
                        ) : null}
                    </div>
                ) : (
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="code">{t('auth.two_factor.code')}</Label>
                        <Input
                            id="code"
                            name="code"
                            // Numeric keypad on a phone, which is where the code
                            // is being read from in the first place.
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            autoFocus
                            value={form.data.code}
                            onChange={(e) => form.setData('code', e.target.value)}
                        />
                        {form.errors.code ? (
                            <p className="text-sm text-red-500">
                                {form.errors.code}
                            </p>
                        ) : null}
                    </div>
                )}

                <Button type="submit" disabled={form.processing}>
                    {t('auth.two_factor.submit')}
                </Button>

                <button
                    type="button"
                    onClick={toggle}
                    className="text-sm text-muted-foreground underline underline-offset-2 hover:text-foreground"
                >
                    {useRecovery
                        ? t('auth.two_factor.use_code')
                        : t('auth.two_factor.use_recovery')}
                </button>
            </form>
        </AuthLayout>
    );
}
