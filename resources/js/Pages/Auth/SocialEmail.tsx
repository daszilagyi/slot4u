import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

import AuthLayout from '@/Layouts/AuthLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';

type SocialEmailProps = {
    /** The provider's brand name ("Facebook"). */
    provider: string;
    /** The address the confirmation link went to, once sent. */
    sentTo: string | null;
};

/**
 * The address step of a social sign-in whose provider returned no e-mail
 * (SLO-252, docs/28 §3). The link in the mail only works in this browser, so
 * the page says so where the person will read it.
 */
export default function SocialEmail({ provider, sentTo }: SocialEmailProps) {
    const t = useTranslations();
    const [editing, setEditing] = useState(sentTo === null);
    const form = useForm({ email: sentTo ?? '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/auth/social/email', {
            onSuccess: () => setEditing(false),
        });
    }

    return (
        <AuthLayout
            title={t('auth.social.email_step.title')}
            subtitle={t('auth.social.email_step.subtitle', { provider })}
            footer={
                <Link href="/login" className="hover:text-foreground">
                    {t('auth.social.email_step.back')}
                </Link>
            }
        >
            <Head title={t('auth.social.email_step.title')} />

            {sentTo !== null && !editing ? (
                <div className="flex flex-col gap-4">
                    <p
                        role="status"
                        className="text-sm font-medium text-primary"
                    >
                        {t('auth.social.email_step.sent', { email: sentTo })}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        className="w-full"
                        onClick={() => setEditing(true)}
                    >
                        {t('auth.social.email_step.resend')}
                    </Button>
                </div>
            ) : (
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="email">
                            {t('auth.social.email_step.email')}
                        </Label>
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            value={form.data.email}
                            autoComplete="email"
                            autoFocus
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                        />
                        {form.errors.email ? (
                            <p className="text-sm text-red-500">
                                {form.errors.email}
                            </p>
                        ) : null}
                    </div>

                    <Button
                        type="submit"
                        className="w-full"
                        disabled={form.processing}
                    >
                        {t('auth.social.email_step.submit')}
                    </Button>
                </form>
            )}
        </AuthLayout>
    );
}
