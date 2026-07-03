import { Head, useForm } from '@inertiajs/react';
import { UserRoundIcon } from 'lucide-react';
import { type FormEvent } from 'react';
import { toast } from 'sonner';

import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/components/admin/EmptyState';
import PageHeader from '@/components/admin/PageHeader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';
import type { StaffProfile } from '@/types';

type EditProps = {
    staff: StaffProfile | null;
};

export default function ProfileEdit({ staff }: EditProps) {
    const t = useTranslations();

    const form = useForm({
        name: staff?.name ?? '',
        title: staff?.title ?? '',
        bio: staff?.bio ?? '',
        color: staff?.color ?? '#6366f1',
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        form.transform((data) => ({
            name: data.name,
            title: data.title || null,
            bio: data.bio || null,
            color: data.color,
        }));

        form.put('/profile', {
            preserveScroll: true,
            onSuccess: () => toast.success(t('admin.profile.saved')),
        });
    }

    return (
        <AdminLayout breadcrumbs={[{ label: t('admin.profile.title') }]}>
            <Head title={t('admin.profile.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('admin.profile.title')}
                    description={t('admin.profile.subtitle')}
                />

                {staff === null ? (
                    <EmptyState
                        icon={UserRoundIcon}
                        title={t('admin.profile.empty_title')}
                        description={t('admin.profile.empty_body')}
                    />
                ) : (
                    <form
                        onSubmit={submit}
                        className="flex max-w-xl flex-col gap-4 rounded-2xl border border-border bg-card p-6"
                    >
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="profile-name">
                                {t('admin.profile.field.name')}
                            </Label>
                            <Input
                                id="profile-name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                            {form.errors.name ? (
                                <p className="text-sm text-destructive">
                                    {form.errors.name}
                                </p>
                            ) : null}
                        </div>
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="profile-title">
                                {t('admin.profile.field.title')}
                            </Label>
                            <Input
                                id="profile-title"
                                value={form.data.title}
                                onChange={(event) =>
                                    form.setData('title', event.target.value)
                                }
                            />
                        </div>
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="profile-bio">
                                {t('admin.profile.field.bio')}
                            </Label>
                            <textarea
                                id="profile-bio"
                                value={form.data.bio}
                                onChange={(event) =>
                                    form.setData('bio', event.target.value)
                                }
                                rows={4}
                                className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                        </div>
                        <div className="flex items-center gap-3">
                            <Label htmlFor="profile-color">
                                {t('admin.profile.field.color')}
                            </Label>
                            <input
                                id="profile-color"
                                type="color"
                                value={form.data.color}
                                onChange={(event) =>
                                    form.setData('color', event.target.value)
                                }
                                className="size-9 cursor-pointer rounded-md border border-input bg-transparent"
                            />
                        </div>
                        <div>
                            <Button type="submit" disabled={form.processing}>
                                {t('admin.common.save')}
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </AdminLayout>
    );
}
