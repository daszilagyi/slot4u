import { Head, Link, router, useForm } from '@inertiajs/react';
import { ContactIcon, PencilIcon, PlusIcon } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { toast } from 'sonner';

import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/components/admin/EmptyState';
import FormSheet from '@/components/admin/FormSheet';
import PageHeader from '@/components/admin/PageHeader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';
import type { CustomerSummary, Paginator } from '@/types';

type IndexProps = {
    customers: Paginator<CustomerSummary>;
    filters: { search: string | null };
    can: { edit: boolean };
};

export default function CustomersIndex({ customers, filters, can }: IndexProps) {
    const t = useTranslations();
    const [search, setSearch] = useState(filters.search ?? '');
    const [sheetOpen, setSheetOpen] = useState(false);
    const [editing, setEditing] = useState<CustomerSummary | null>(null);

    const form = useForm({ name: '', email: '', phone: '' });

    function submitSearch(event: FormEvent) {
        event.preventDefault();
        router.get(
            '/customers',
            { search },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function openCreate() {
        setEditing(null);
        form.clearErrors();
        form.setDefaults({ name: '', email: '', phone: '' });
        form.reset();
        setSheetOpen(true);
    }

    function openEdit(customer: CustomerSummary) {
        setEditing(customer);
        form.clearErrors();
        form.setData({
            name: customer.name,
            email: customer.email,
            phone: customer.phone ?? '',
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
                        ? t('admin.customers.updated')
                        : t('admin.customers.created'),
                );
                setSheetOpen(false);
            },
        };

        form.transform((data) => ({
            name: data.name,
            email: data.email,
            phone: data.phone || null,
        }));

        if (editing) {
            form.put(`/customers/${editing.id}`, options);
        } else {
            form.post('/customers', options);
        }
    }

    const isEmpty = customers.data.length === 0;

    return (
        <AdminLayout breadcrumbs={[{ label: t('admin.customers.title') }]}>
            <Head title={t('admin.customers.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('admin.customers.title')}
                    description={t('admin.customers.subtitle')}
                    actions={
                        can.edit ? (
                            <Button onClick={openCreate}>
                                <PlusIcon className="size-4" />
                                {t('admin.customers.add')}
                            </Button>
                        ) : undefined
                    }
                />

                <form onSubmit={submitSearch} className="flex gap-3">
                    <Input
                        value={search}
                        placeholder={t('admin.customers.search_placeholder')}
                        onChange={(e) => setSearch(e.target.value)}
                        className="max-w-sm"
                    />
                    <Button type="submit" variant="outline">
                        {t('admin.common.search')}
                    </Button>
                </form>

                {isEmpty ? (
                    <EmptyState
                        icon={ContactIcon}
                        title={
                            filters.search
                                ? t('admin.customers.empty_search')
                                : t('admin.customers.empty')
                        }
                        description=""
                        action={
                            can.edit && !filters.search ? (
                                <Button onClick={openCreate}>
                                    <PlusIcon className="size-4" />
                                    {t('admin.customers.add')}
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <div className="overflow-hidden rounded-xl border border-border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-3 font-medium">
                                        {t('admin.customers.field.name')}
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        {t('admin.customers.field.email')}
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        {t('admin.customers.field.phone')}
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        {t('admin.customers.card.bookings')}
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        {t('admin.common.actions')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {customers.data.map((customer) => (
                                    <tr
                                        key={customer.id}
                                        className="border-t border-border"
                                    >
                                        <td className="px-4 py-3 font-medium">
                                            <Link
                                                href={`/customers/${customer.id}`}
                                                className="hover:underline"
                                            >
                                                {customer.name}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {customer.email}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {customer.phone ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {customer.bookings_count}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-2">
                                                {can.edit ? (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() =>
                                                            openEdit(customer)
                                                        }
                                                        aria-label={t(
                                                            'admin.common.edit',
                                                        )}
                                                    >
                                                        <PencilIcon className="size-4" />
                                                    </Button>
                                                ) : null}
                                                <Button size="sm" asChild>
                                                    <Link
                                                        href={`/customers/${customer.id}`}
                                                    >
                                                        {t('admin.common.view')}
                                                    </Link>
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {customers.last_page > 1 ? (
                    <div className="flex flex-wrap gap-1">
                        {customers.links.map((link, i) => (
                            <button
                                key={i}
                                disabled={!link.url}
                                onClick={() =>
                                    link.url &&
                                    router.get(
                                        link.url,
                                        {},
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                        },
                                    )
                                }
                                className={`rounded-md px-3 py-1.5 text-sm ${
                                    link.active
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-accent disabled:opacity-40'
                                }`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>

            <FormSheet
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                title={
                    editing
                        ? t('admin.customers.edit')
                        : t('admin.customers.add')
                }
                submitLabel={t('admin.common.save')}
                cancelLabel={t('admin.common.cancel')}
                onSubmit={submit}
                submitting={form.processing}
            >
                <div className="flex flex-col gap-2">
                    <Label htmlFor="customer-name">
                        {t('admin.customers.field.name')}
                    </Label>
                    <Input
                        id="customer-name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        autoFocus
                    />
                    {form.errors.name ? (
                        <p className="text-sm text-destructive">
                            {form.errors.name}
                        </p>
                    ) : null}
                </div>
                <div className="flex flex-col gap-2">
                    <Label htmlFor="customer-email">
                        {t('admin.customers.field.email')}
                    </Label>
                    <Input
                        id="customer-email"
                        type="email"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                    {form.errors.email ? (
                        <p className="text-sm text-destructive">
                            {form.errors.email}
                        </p>
                    ) : null}
                </div>
                <div className="flex flex-col gap-2">
                    <Label htmlFor="customer-phone">
                        {t('admin.customers.field.phone')}
                    </Label>
                    <Input
                        id="customer-phone"
                        value={form.data.phone}
                        onChange={(e) => form.setData('phone', e.target.value)}
                    />
                    {form.errors.phone ? (
                        <p className="text-sm text-destructive">
                            {form.errors.phone}
                        </p>
                    ) : null}
                </div>
            </FormSheet>
        </AdminLayout>
    );
}
