import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { toast } from 'sonner';

import PublicLayout from '@/Layouts/PublicLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';

type ProfileProps = {
    profile: {
        name: string;
        email: string;
        phone: string | null;
    };
};

export default function Profile({ profile }: ProfileProps) {
    const t = useTranslations();

    const details = useForm({
        name: profile.name,
        phone: profile.phone ?? '',
    });

    const password = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    function submitDetails(event: FormEvent) {
        event.preventDefault();
        details.put('/my/profile', {
            preserveScroll: true,
            onSuccess: () => toast.success(t('tenant.my.profile.saved')),
        });
    }

    function submitPassword(event: FormEvent) {
        event.preventDefault();
        password.put('/my/password', {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(t('tenant.my.profile.password_saved'));
                password.reset();
            },
        });
    }

    return (
        <PublicLayout>
            <Head title={t('tenant.my.profile.title')} />

            <div className="mx-auto flex w-full max-w-xl flex-col gap-8 px-4 py-12 sm:px-6">
                <header className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('tenant.my.profile.title')}
                    </h1>
                    <p className="text-muted-foreground">
                        {t('tenant.my.profile.subtitle')}
                    </p>
                </header>

                <form
                    onSubmit={submitDetails}
                    className="flex flex-col gap-4 rounded-xl border border-border bg-card p-6"
                >
                    <h2 className="text-sm font-semibold tracking-tight text-muted-foreground uppercase">
                        {t('tenant.my.profile.section_details')}
                    </h2>

                    <div className="flex flex-col gap-2">
                        <Label htmlFor="name">
                            {t('tenant.my.profile.name')}
                        </Label>
                        <Input
                            id="name"
                            value={details.data.name}
                            onChange={(e) =>
                                details.setData('name', e.target.value)
                            }
                        />
                        {details.errors.name ? (
                            <p className="text-sm text-red-500">
                                {details.errors.name}
                            </p>
                        ) : null}
                    </div>

                    <div className="flex flex-col gap-2">
                        <Label htmlFor="email">
                            {t('tenant.my.profile.email')}
                        </Label>
                        <Input
                            id="email"
                            value={profile.email}
                            disabled
                            readOnly
                        />
                        <p className="text-xs text-muted-foreground">
                            {t('tenant.my.profile.email_hint')}
                        </p>
                    </div>

                    <div className="flex flex-col gap-2">
                        <Label htmlFor="phone">
                            {t('tenant.my.profile.phone')}
                        </Label>
                        <Input
                            id="phone"
                            type="tel"
                            autoComplete="tel"
                            value={details.data.phone}
                            onChange={(e) =>
                                details.setData('phone', e.target.value)
                            }
                        />
                        {details.errors.phone ? (
                            <p className="text-sm text-red-500">
                                {details.errors.phone}
                            </p>
                        ) : null}
                    </div>

                    <Button
                        type="submit"
                        className="self-start"
                        disabled={details.processing}
                    >
                        {t('tenant.my.profile.save')}
                    </Button>
                </form>

                <form
                    onSubmit={submitPassword}
                    className="flex flex-col gap-4 rounded-xl border border-border bg-card p-6"
                >
                    <h2 className="text-sm font-semibold tracking-tight text-muted-foreground uppercase">
                        {t('tenant.my.profile.section_password')}
                    </h2>

                    <div className="flex flex-col gap-2">
                        <Label htmlFor="current_password">
                            {t('tenant.my.profile.current_password')}
                        </Label>
                        <Input
                            id="current_password"
                            type="password"
                            autoComplete="current-password"
                            value={password.data.current_password}
                            onChange={(e) =>
                                password.setData(
                                    'current_password',
                                    e.target.value,
                                )
                            }
                        />
                        {password.errors.current_password ? (
                            <p className="text-sm text-red-500">
                                {password.errors.current_password}
                            </p>
                        ) : null}
                    </div>

                    <div className="flex flex-col gap-2">
                        <Label htmlFor="password">
                            {t('tenant.my.profile.new_password')}
                        </Label>
                        <Input
                            id="password"
                            type="password"
                            autoComplete="new-password"
                            value={password.data.password}
                            onChange={(e) =>
                                password.setData('password', e.target.value)
                            }
                        />
                        {password.errors.password ? (
                            <p className="text-sm text-red-500">
                                {password.errors.password}
                            </p>
                        ) : null}
                    </div>

                    <div className="flex flex-col gap-2">
                        <Label htmlFor="password_confirmation">
                            {t('tenant.my.profile.new_password_confirmation')}
                        </Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            autoComplete="new-password"
                            value={password.data.password_confirmation}
                            onChange={(e) =>
                                password.setData(
                                    'password_confirmation',
                                    e.target.value,
                                )
                            }
                        />
                    </div>

                    <Button
                        type="submit"
                        className="self-start"
                        disabled={password.processing}
                    >
                        {t('tenant.my.profile.save_password')}
                    </Button>
                </form>
            </div>
        </PublicLayout>
    );
}
