import { Head, router, useForm } from '@inertiajs/react';
import { MailIcon, PencilIcon, PlusIcon, Trash2Icon, UsersIcon } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { toast } from 'sonner';

import AdminLayout from '@/Layouts/AdminLayout';
import ConfirmDialog from '@/components/admin/ConfirmDialog';
import EmptyState from '@/components/admin/EmptyState';
import FormSheet from '@/components/admin/FormSheet';
import PageHeader from '@/components/admin/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';
import type { ResourceLimit, StaffMember } from '@/types';

type IndexProps = {
    staff: StaffMember[];
    limits: { employees: ResourceLimit };
};

function atLimit(limit: ResourceLimit): boolean {
    return limit.max !== null && limit.used >= limit.max;
}

export default function StaffIndex({ staff, limits }: IndexProps) {
    const t = useTranslations();

    const [sheetOpen, setSheetOpen] = useState(false);
    const [editing, setEditing] = useState<StaffMember | null>(null);
    const [deleting, setDeleting] = useState<StaffMember | null>(null);

    const form = useForm({
        name: '',
        title: '',
        bio: '',
        color: '#6366f1',
        active: true,
        email: '',
    });

    const staffFull = atLimit(limits.employees);
    // Email invites a login user; once linked it is fixed (managed via the user).
    const canInvite = editing === null || editing.user === null;

    function openCreate() {
        setEditing(null);
        form.clearErrors();
        form.setDefaults({
            name: '',
            title: '',
            bio: '',
            color: '#6366f1',
            active: true,
            email: '',
        });
        form.reset();
        setSheetOpen(true);
    }

    function openEdit(member: StaffMember) {
        setEditing(member);
        form.clearErrors();
        form.setData({
            name: member.name,
            title: member.title ?? '',
            bio: member.bio ?? '',
            color: member.color,
            active: member.active,
            email: '',
        });
        setSheetOpen(true);
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(
                    editing
                        ? t('admin.staff.updated')
                        : t('admin.staff.created'),
                );
                setSheetOpen(false);
            },
        };

        form.transform((data) => ({
            name: data.name,
            title: data.title || null,
            bio: data.bio || null,
            color: data.color,
            active: data.active,
            email: canInvite && data.email ? data.email : null,
        }));

        if (editing) {
            form.put(`/staff/${editing.id}`, options);
        } else {
            form.post('/staff', options);
        }
    }

    function confirmDelete() {
        if (!deleting) {
            return;
        }

        router.delete(`/staff/${deleting.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success(t('admin.staff.deleted')),
            onError: (errors) =>
                toast.error(errors.delete ?? t('admin.staff.error.has_bookings')),
        });
    }

    return (
        <AdminLayout breadcrumbs={[{ label: t('admin.staff.title') }]}>
            <Head title={t('admin.staff.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('admin.staff.title')}
                    description={t('admin.staff.subtitle')}
                    actions={
                        <div className="flex items-center gap-3">
                            <Badge variant="outline">
                                {t('admin.staff.limit_badge', {
                                    used: limits.employees.used,
                                    max: limits.employees.max ?? '∞',
                                })}
                            </Badge>
                            <Button
                                onClick={openCreate}
                                disabled={staffFull}
                                title={
                                    staffFull
                                        ? t('admin.staff.limit_at_max')
                                        : undefined
                                }
                            >
                                <PlusIcon className="size-4" />
                                {t('admin.staff.add')}
                            </Button>
                        </div>
                    }
                />

                {staff.length === 0 ? (
                    <EmptyState
                        icon={UsersIcon}
                        title={t('admin.staff.empty_title')}
                        description={t('admin.staff.empty_body')}
                        action={
                            <Button onClick={openCreate} disabled={staffFull}>
                                <PlusIcon className="size-4" />
                                {t('admin.staff.add')}
                            </Button>
                        }
                    />
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {staff.map((member) => (
                            <li
                                key={member.id}
                                className="flex flex-col gap-3 rounded-2xl border border-border bg-card p-5"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="flex items-center gap-3">
                                        <span
                                            aria-hidden="true"
                                            className="size-9 shrink-0 rounded-full"
                                            style={{
                                                backgroundColor: member.color,
                                            }}
                                        />
                                        <div className="flex flex-col">
                                            <div className="flex items-center gap-2">
                                                <span className="font-semibold">
                                                    {member.name}
                                                </span>
                                                {member.active ? null : (
                                                    <Badge variant="secondary">
                                                        {t(
                                                            'admin.staff.inactive',
                                                        )}
                                                    </Badge>
                                                )}
                                            </div>
                                            {member.title ? (
                                                <span className="text-sm text-muted-foreground">
                                                    {member.title}
                                                </span>
                                            ) : null}
                                        </div>
                                    </div>
                                    <div className="flex gap-1">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => openEdit(member)}
                                            aria-label={t('admin.common.edit')}
                                        >
                                            <PencilIcon className="size-4" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => setDeleting(member)}
                                            aria-label={t('admin.common.delete')}
                                        >
                                            <Trash2Icon className="size-4 text-destructive" />
                                        </Button>
                                    </div>
                                </div>

                                {member.bio ? (
                                    <p className="text-sm text-muted-foreground">
                                        {member.bio}
                                    </p>
                                ) : null}

                                <div className="mt-auto">
                                    {member.user ? (
                                        <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                                            <MailIcon className="size-3.5" />
                                            {member.user.email}
                                            <Badge variant="outline">
                                                {t('admin.staff.invited')}
                                            </Badge>
                                        </span>
                                    ) : (
                                        <Badge variant="secondary">
                                            {t('admin.staff.no_login')}
                                        </Badge>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <FormSheet
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                title={
                    editing
                        ? t('admin.staff.edit_title')
                        : t('admin.staff.new_title')
                }
                description={t('admin.staff.form_desc')}
                submitLabel={t('admin.common.save')}
                cancelLabel={t('admin.common.cancel')}
                onSubmit={submit}
                submitting={form.processing}
            >
                <div className="flex flex-col gap-2">
                    <Label htmlFor="staff-name">
                        {t('admin.staff.field.name')}
                    </Label>
                    <Input
                        id="staff-name"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        autoFocus
                    />
                    {form.errors.name ? (
                        <p className="text-sm text-destructive">
                            {form.errors.name}
                        </p>
                    ) : null}
                </div>
                <div className="flex flex-col gap-2">
                    <Label htmlFor="staff-title">
                        {t('admin.staff.field.title')}
                    </Label>
                    <Input
                        id="staff-title"
                        value={form.data.title}
                        onChange={(event) =>
                            form.setData('title', event.target.value)
                        }
                    />
                </div>
                <div className="flex flex-col gap-2">
                    <Label htmlFor="staff-bio">
                        {t('admin.staff.field.bio')}
                    </Label>
                    <textarea
                        id="staff-bio"
                        value={form.data.bio}
                        onChange={(event) =>
                            form.setData('bio', event.target.value)
                        }
                        rows={3}
                        className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                </div>
                <div className="flex items-center gap-3">
                    <Label htmlFor="staff-color">
                        {t('admin.staff.field.color')}
                    </Label>
                    <input
                        id="staff-color"
                        type="color"
                        value={form.data.color}
                        onChange={(event) =>
                            form.setData('color', event.target.value)
                        }
                        className="size-9 cursor-pointer rounded-md border border-input bg-transparent"
                    />
                </div>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={form.data.active}
                        onChange={(event) =>
                            form.setData('active', event.target.checked)
                        }
                        className="size-4 rounded border-input"
                    />
                    {t('admin.staff.field.active')}
                </label>

                {canInvite ? (
                    <div className="flex flex-col gap-2 border-t border-border pt-4">
                        <Label htmlFor="staff-email">
                            {t('admin.staff.field.email')}
                        </Label>
                        <Input
                            id="staff-email"
                            type="email"
                            value={form.data.email}
                            onChange={(event) =>
                                form.setData('email', event.target.value)
                            }
                            placeholder={t('admin.staff.field.email_placeholder')}
                        />
                        <p className="text-xs text-muted-foreground">
                            {t('admin.staff.invite_hint')}
                        </p>
                        {form.errors.email ? (
                            <p className="text-sm text-destructive">
                                {form.errors.email}
                            </p>
                        ) : null}
                    </div>
                ) : (
                    <div className="border-t border-border pt-4 text-sm text-muted-foreground">
                        {t('admin.staff.already_invited', {
                            email: editing?.user?.email ?? '',
                        })}
                    </div>
                )}
            </FormSheet>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={t('admin.staff.delete_title')}
                description={t('admin.staff.delete_body', {
                    name: deleting?.name ?? '',
                })}
                confirmLabel={t('admin.common.delete')}
                cancelLabel={t('admin.common.cancel')}
                onConfirm={confirmDelete}
                destructive
            />
        </AdminLayout>
    );
}
