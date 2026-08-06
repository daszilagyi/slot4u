import { Head, useForm } from '@inertiajs/react';
import { LockIcon, PencilIcon, RotateCcwIcon, Trash2Icon } from 'lucide-react';
import { toast } from 'sonner';

import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/components/admin/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';

type CatalogPermission = {
    code: string;
    /** Feature flag that makes this code untoggleable; null = freely editable. */
    locked_by: string | null;
};

type CatalogGroup = {
    key: string;
    permissions: CatalogPermission[];
};

type Role = {
    name: string;
    /** Built-in roles have translated labels; a custom role's name is its label. */
    built_in: boolean;
    permissions: string[];
    editable: boolean;
    renamable: boolean;
    deletable: boolean;
    resettable: boolean;
    is_own: boolean;
    holders: number;
    customized: boolean;
};

type StaffUser = {
    id: number;
    name: string;
    email: string;
    roles: string[];
    /** Direct grants only — what the roles already give is not repeated here. */
    permissions: string[];
    editable: boolean;
    is_self: boolean;
};

type IndexProps = {
    roles: Role[];
    groups: CatalogGroup[];
    users: StaffUser[];
};

/** Built-in roles have a lang key; a custom role is displayed by its own name. */
function useRoleLabel() {
    const t = useTranslations();

    return (role: Pick<Role, 'name' | 'built_in'>) =>
        role.built_in ? t(`role_name.${role.name}`) : role.name;
}

/**
 * The tenant's role editor (SLO-141, SLO-142): one card per role — the four
 * seeded ones plus any the tenant added — each listing the permission catalog as
 * checkboxes, followed by the staff roster where a single user's roles and
 * individual extras are set.
 *
 * Locked rows still render — greyed out with the reason — because a silently
 * absent permission reads as a bug, while a locked one reads as an answer.
 */
export default function RolesIndex({ roles, groups, users }: IndexProps) {
    const t = useTranslations();

    return (
        <AdminLayout breadcrumbs={[{ label: t('admin.roles.title') }]}>
            <Head title={t('admin.roles.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('admin.roles.title')}
                    description={t('admin.roles.subtitle')}
                />

                <p className="max-w-3xl text-sm text-muted-foreground">
                    {t('admin.roles.admin_only_note')}
                </p>

                <div className="flex flex-col gap-4">
                    {roles.map((role) => (
                        <RoleCard key={role.name} role={role} groups={groups} />
                    ))}
                </div>

                <CreateRoleCard />

                <UsersSection users={users} roles={roles} groups={groups} />
            </div>
        </AdminLayout>
    );
}

function RoleCard({ role, groups }: { role: Role; groups: CatalogGroup[] }) {
    const t = useTranslations();
    const label = useRoleLabel();

    const form = useForm<{ permissions: string[] }>({ permissions: role.permissions });
    const nameForm = useForm<{ name: string }>({ name: role.name });

    function toggle(code: string) {
        form.setData(
            'permissions',
            form.data.permissions.includes(code)
                ? form.data.permissions.filter((value) => value !== code)
                : [...form.data.permissions, code],
        );
    }

    function submit() {
        form.put(`/settings/roles/${encodeURIComponent(role.name)}`, {
            preserveScroll: true,
            onSuccess: () => toast.success(t('admin.roles.updated')),
        });
    }

    function reset() {
        if (!window.confirm(t('admin.roles.reset_confirm', { role: label(role) }))) {
            return;
        }

        // Posted through the same form object so `processing` disables both
        // buttons for the duration; the page reloads with the fresh grant, so
        // there is no local state to re-seed afterwards.
        form.post(`/settings/roles/${encodeURIComponent(role.name)}/reset`, {
            preserveScroll: true,
            onSuccess: () => toast.success(t('admin.roles.reset_done')),
        });
    }

    function rename() {
        const next = window.prompt(t('admin.roles.rename_prompt'), role.name);

        if (next === null || next.trim() === '' || next === role.name) {
            return;
        }

        nameForm.transform(() => ({ name: next.trim() }));
        nameForm.patch(`/settings/roles/${encodeURIComponent(role.name)}/name`, {
            preserveScroll: true,
            onSuccess: () => toast.success(t('admin.roles.renamed')),
            onError: (errors) => toast.error(errors.name ?? t('admin.roles.rename')),
        });
    }

    function destroy() {
        if (!window.confirm(t('admin.roles.delete_confirm', { role: label(role) }))) {
            return;
        }

        nameForm.delete(`/settings/roles/${encodeURIComponent(role.name)}`, {
            preserveScroll: true,
            onSuccess: () => toast.success(t('admin.roles.deleted')),
            onError: (errors) => toast.error(errors.role ?? t('admin.roles.delete')),
        });
    }

    const lockReason = role.is_own
        ? t('admin.roles.locked_own')
        : role.name === 'tenant-admin'
          ? t('admin.roles.locked_tenant_admin')
          : role.name === 'customer'
            ? t('admin.roles.locked_customer')
            : null;

    return (
        <section className="rounded-2xl border border-border bg-card p-5">
            <header className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex items-center gap-3">
                        <h2 className="font-medium">{label(role)}</h2>
                        <Badge
                            variant={
                                !role.built_in || role.customized ? 'default' : 'secondary'
                            }
                        >
                            {!role.built_in
                                ? t('admin.roles.custom_badge')
                                : role.customized
                                  ? t('admin.roles.customized_badge')
                                  : t('admin.roles.default_badge')}
                        </Badge>
                        <span className="text-xs text-muted-foreground">
                            {role.holders === 0
                                ? t('admin.roles.holders_none')
                                : t('admin.roles.holders', { count: role.holders })}
                        </span>
                        {!role.editable ? (
                            <LockIcon
                                className="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                        ) : null}
                    </div>
                    <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                        {role.built_in
                            ? t(`role_description.${role.name}`)
                            : t('admin.roles.custom_description')}
                    </p>
                </div>

                {role.editable ? (
                    <div className="flex items-center gap-2">
                        {role.renamable ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={rename}
                                disabled={nameForm.processing}
                            >
                                <PencilIcon className="size-4" aria-hidden="true" />
                                {t('admin.roles.rename')}
                            </Button>
                        ) : null}
                        {role.deletable ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={destroy}
                                disabled={nameForm.processing}
                            >
                                <Trash2Icon className="size-4" aria-hidden="true" />
                                {t('admin.roles.delete')}
                            </Button>
                        ) : null}
                        {role.resettable && role.customized ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={reset}
                                disabled={form.processing}
                            >
                                <RotateCcwIcon className="size-4" aria-hidden="true" />
                                {t('admin.roles.reset')}
                            </Button>
                        ) : null}
                        <Button type="button" onClick={submit} disabled={form.processing}>
                            {t('admin.roles.save')}
                        </Button>
                    </div>
                ) : (
                    <span className="text-sm text-muted-foreground">
                        {t('admin.roles.permission_count', {
                            count: role.permissions.length,
                        })}
                    </span>
                )}
            </header>

            {lockReason !== null ? (
                <p className="mb-4 rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                    {lockReason}
                </p>
            ) : null}

            <div className="grid gap-x-8 gap-y-5 sm:grid-cols-2">
                {groups.map((group) => (
                    <fieldset key={group.key} disabled={!role.editable}>
                        <legend className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {t(`admin.roles.group.${group.key}`)}
                        </legend>
                        <div className="flex flex-col">
                            {group.permissions.map((permission) => {
                                // A feature-locked code cannot be toggled either
                                // way: the grant it currently has is preserved by
                                // the server on every save, so the checkbox shows
                                // the truth rather than an editable lie.
                                const locked =
                                    !role.editable || permission.locked_by !== null;

                                return (
                                    <label
                                        key={permission.code}
                                        className={`flex items-start gap-2.5 py-1.5 text-sm ${
                                            locked ? 'text-muted-foreground' : ''
                                        }`}
                                        title={
                                            permission.locked_by !== null
                                                ? t('admin.roles.locked_feature', {
                                                      feature: t(
                                                          `features.${permission.locked_by}`,
                                                      ),
                                                  })
                                                : undefined
                                        }
                                    >
                                        <input
                                            type="checkbox"
                                            checked={form.data.permissions.includes(
                                                permission.code,
                                            )}
                                            disabled={locked}
                                            onChange={() => toggle(permission.code)}
                                            className="mt-0.5 size-4 rounded border-input"
                                        />
                                        <span>
                                            {t(`permission.${permission.code}`)}
                                            {permission.locked_by !== null ? (
                                                <span className="block text-xs">
                                                    {t('admin.roles.locked_feature', {
                                                        feature: t(
                                                            `features.${permission.locked_by}`,
                                                        ),
                                                    })}
                                                </span>
                                            ) : null}
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                    </fieldset>
                ))}
            </div>
        </section>
    );
}

/**
 * Adding a role of the tenant's own. It is created empty on purpose — the grant
 * is then ticked on the role's own card, so a new role never arrives carrying
 * permissions nobody chose.
 */
function CreateRoleCard() {
    const t = useTranslations();
    const form = useForm<{ name: string }>({ name: '' });

    function submit(event: React.FormEvent) {
        event.preventDefault();

        form.post('/settings/roles', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('name');
                toast.success(t('admin.roles.created'));
            },
        });
    }

    return (
        <section className="rounded-2xl border border-dashed border-border bg-card/50 p-5">
            <h2 className="font-medium">{t('admin.roles.create_title')}</h2>
            <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                {t('admin.roles.create_hint')}
            </p>

            <form onSubmit={submit} className="mt-4 flex flex-wrap items-end gap-3">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="new-role-name">{t('admin.roles.name_label')}</Label>
                    <Input
                        id="new-role-name"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        placeholder={t('admin.roles.name_placeholder')}
                        maxLength={60}
                        className="w-64"
                    />
                </div>
                <Button type="submit" disabled={form.processing || form.data.name === ''}>
                    {t('admin.roles.create')}
                </Button>
            </form>

            {form.errors.name ? (
                <p className="mt-2 text-sm text-destructive">{form.errors.name}</p>
            ) : null}
        </section>
    );
}

function UsersSection({
    users,
    roles,
    groups,
}: {
    users: StaffUser[];
    roles: Role[];
    groups: CatalogGroup[];
}) {
    const t = useTranslations();

    // The customer role is a members-area role; it is never assigned from here.
    const assignable = roles.filter((role) => role.name !== 'customer');

    return (
        <div className="flex flex-col gap-4">
            <div>
                <h2 className="text-lg font-medium">{t('admin.roles.users_title')}</h2>
                <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                    {t('admin.roles.users_subtitle')}
                </p>
            </div>

            {users.length === 0 ? (
                <p className="rounded-2xl border border-border bg-card p-5 text-sm text-muted-foreground">
                    {t('admin.roles.users_empty')}
                </p>
            ) : (
                users.map((user) => (
                    <UserCard
                        key={user.id}
                        user={user}
                        roles={assignable}
                        groups={groups}
                    />
                ))
            )}
        </div>
    );
}

function UserCard({
    user,
    roles,
    groups,
}: {
    user: StaffUser;
    roles: Role[];
    groups: CatalogGroup[];
}) {
    const t = useTranslations();
    const label = useRoleLabel();

    const form = useForm<{ roles: string[]; permissions: string[] }>({
        roles: user.roles,
        permissions: user.permissions,
    });

    // What the selected roles already grant. Shown as "inherited" rather than as
    // a ticked individual box, so the admin can tell a role's doing from their
    // own — and so unticking a role does not look like it revoked a grant the
    // user never held individually.
    const inherited = new Set(
        roles
            .filter((role) => form.data.roles.includes(role.name))
            .flatMap((role) => role.permissions),
    );

    function toggleRole(name: string) {
        form.setData(
            'roles',
            form.data.roles.includes(name)
                ? form.data.roles.filter((value) => value !== name)
                : [...form.data.roles, name],
        );
    }

    function togglePermission(code: string) {
        form.setData(
            'permissions',
            form.data.permissions.includes(code)
                ? form.data.permissions.filter((value) => value !== code)
                : [...form.data.permissions, code],
        );
    }

    function submit() {
        form.put(`/settings/users/${user.id}/rbac`, {
            preserveScroll: true,
            onSuccess: () => toast.success(t('admin.roles.user_updated')),
        });
    }

    return (
        <section className="rounded-2xl border border-border bg-card p-5">
            <header className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex items-center gap-3">
                        <h3 className="font-medium">{user.name}</h3>
                        {!user.editable ? (
                            <LockIcon
                                className="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                        ) : null}
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground">{user.email}</p>
                </div>

                {user.editable ? (
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={form.processing || form.data.roles.length === 0}
                    >
                        {t('admin.roles.save')}
                    </Button>
                ) : null}
            </header>

            {user.is_self ? (
                <p className="mb-4 rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                    {t('admin.roles.locked_self')}
                </p>
            ) : null}

            <fieldset disabled={!user.editable} className="mb-5">
                <legend className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t('admin.roles.users_roles')}
                </legend>
                <div className="flex flex-wrap gap-x-6 gap-y-1.5">
                    {roles.map((role) => (
                        <label
                            key={role.name}
                            className="flex items-center gap-2.5 text-sm"
                        >
                            <input
                                type="checkbox"
                                checked={form.data.roles.includes(role.name)}
                                onChange={() => toggleRole(role.name)}
                                className="size-4 rounded border-input"
                            />
                            {label(role)}
                        </label>
                    ))}
                </div>
                {form.data.roles.length === 0 ? (
                    <p className="mt-2 text-sm text-destructive">
                        {t('admin.roles.users_min_role')}
                    </p>
                ) : null}
            </fieldset>

            <fieldset disabled={!user.editable}>
                <legend className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t('admin.roles.users_extra')}
                </legend>
                <div className="grid gap-x-8 gap-y-5 sm:grid-cols-2">
                    {groups.map((group) => (
                        <div key={group.key}>
                            <p className="mb-2 text-xs text-muted-foreground">
                                {t(`admin.roles.group.${group.key}`)}
                            </p>
                            <div className="flex flex-col">
                                {group.permissions.map((permission) => {
                                    const fromRole = inherited.has(permission.code);
                                    const locked =
                                        !user.editable ||
                                        fromRole ||
                                        permission.locked_by !== null;

                                    return (
                                        <label
                                            key={permission.code}
                                            className={`flex items-start gap-2.5 py-1.5 text-sm ${
                                                locked ? 'text-muted-foreground' : ''
                                            }`}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={
                                                    fromRole ||
                                                    form.data.permissions.includes(
                                                        permission.code,
                                                    )
                                                }
                                                disabled={locked}
                                                onChange={() =>
                                                    togglePermission(permission.code)
                                                }
                                                className="mt-0.5 size-4 rounded border-input"
                                            />
                                            <span>
                                                {t(`permission.${permission.code}`)}
                                                {fromRole ? (
                                                    <span className="block text-xs">
                                                        {t('admin.roles.users_inherited')}
                                                    </span>
                                                ) : permission.locked_by !== null ? (
                                                    <span className="block text-xs">
                                                        {t('admin.roles.locked_feature', {
                                                            feature: t(
                                                                `features.${permission.locked_by}`,
                                                            ),
                                                        })}
                                                    </span>
                                                ) : null}
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                        </div>
                    ))}
                </div>
            </fieldset>
        </section>
    );
}
