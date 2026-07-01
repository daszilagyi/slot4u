import { Head } from '@inertiajs/react';
import {
    BlocksIcon,
    PencilIcon,
    PlusIcon,
    SearchIcon,
    Trash2Icon,
} from 'lucide-react';
import { useMemo, useRef, useState, type FormEvent } from 'react';
import { toast } from 'sonner';

import AdminLayout from '@/Layouts/AdminLayout';
import ConfirmDialog from '@/components/admin/ConfirmDialog';
import DataTable, {
    Pager,
    type Column,
    type SortState,
} from '@/components/admin/DataTable';
import EmptyState from '@/components/admin/EmptyState';
import FormSheet from '@/components/admin/FormSheet';
import PageHeader from '@/components/admin/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';

type ItemType = 'room' | 'equipment';
type ItemStatus = 'active' | 'inactive';
type Item = {
    id: number;
    name: string;
    type: ItemType;
    status: ItemStatus;
    updatedAt: string;
};

const PAGE_SIZE = 5;

const SEED: Item[] = [
    {
        id: 1,
        name: 'Terem A',
        type: 'room',
        status: 'active',
        updatedAt: '2026-06-30T09:15:00Z',
    },
    {
        id: 2,
        name: 'Projektor',
        type: 'equipment',
        status: 'active',
        updatedAt: '2026-06-28T14:00:00Z',
    },
    {
        id: 3,
        name: 'Tárgyaló',
        type: 'room',
        status: 'inactive',
        updatedAt: '2026-06-20T11:30:00Z',
    },
];

/**
 * Sample CRUD page (SLO-15 acceptance criterion) assembled purely from the
 * shared building blocks — DataTable, FormSheet, ConfirmDialog, EmptyState,
 * toasts. State is client-side and ephemeral; the real resource pages (SLO-16+)
 * swap this in-memory store for Inertia server data.
 */
export default function Showcase() {
    const t = useTranslations();

    const [items, setItems] = useState<Item[]>(SEED);
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<SortState>(null);
    const [page, setPage] = useState(1);

    const [sheetOpen, setSheetOpen] = useState(false);
    const [editing, setEditing] = useState<Item | null>(null);
    const [name, setName] = useState('');
    const [type, setType] = useState<ItemType>('room');
    const [status, setStatus] = useState<ItemStatus>('active');

    const [deleting, setDeleting] = useState<Item | null>(null);

    const nextId = useRef(SEED.length + 1);

    const filtered = useMemo(() => {
        const term = search.trim().toLowerCase();
        const rows = term
            ? items.filter((item) => item.name.toLowerCase().includes(term))
            : items;

        if (!sort) {
            return rows;
        }

        const factor = sort.dir === 'asc' ? 1 : -1;

        return [...rows].sort((a, b) => {
            const av = String(a[sort.key as keyof Item]);
            const bv = String(b[sort.key as keyof Item]);

            return av.localeCompare(bv, 'hu') * factor;
        });
    }, [items, search, sort]);

    const lastPage = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
    const current = Math.min(page, lastPage);
    const pageRows = filtered.slice(
        (current - 1) * PAGE_SIZE,
        current * PAGE_SIZE,
    );

    function toggleSort(key: string) {
        setSort((previous) =>
            previous?.key === key
                ? { key, dir: previous.dir === 'asc' ? 'desc' : 'asc' }
                : { key, dir: 'asc' },
        );
    }

    function openCreate() {
        setEditing(null);
        setName('');
        setType('room');
        setStatus('active');
        setSheetOpen(true);
    }

    function openEdit(item: Item) {
        setEditing(item);
        setName(item.name);
        setType(item.type);
        setStatus(item.status);
        setSheetOpen(true);
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        if (name.trim() === '') {
            toast.error(t('admin.showcase.required'));

            return;
        }

        const now = new Date().toISOString();

        if (editing) {
            setItems((rows) =>
                rows.map((row) =>
                    row.id === editing.id
                        ? { ...row, name, type, status, updatedAt: now }
                        : row,
                ),
            );
            toast.success(t('admin.showcase.updated'));
        } else {
            setItems((rows) => [
                { id: nextId.current++, name, type, status, updatedAt: now },
                ...rows,
            ]);
            toast.success(t('admin.showcase.created'));
        }

        setSheetOpen(false);
    }

    function confirmDelete() {
        if (!deleting) {
            return;
        }

        setItems((rows) => rows.filter((row) => row.id !== deleting.id));
        toast.success(t('admin.showcase.deleted'));
    }

    const columns: Column<Item>[] = [
        {
            key: 'name',
            header: t('admin.showcase.col_name'),
            sortable: true,
            cell: (row) => <span className="font-medium">{row.name}</span>,
        },
        {
            key: 'type',
            header: t('admin.showcase.col_type'),
            sortable: true,
            cell: (row) => (
                <Badge variant="outline">
                    {t(`admin.showcase.type_${row.type}`)}
                </Badge>
            ),
        },
        {
            key: 'status',
            header: t('admin.showcase.col_status'),
            sortable: true,
            cell: (row) => (
                <Badge
                    variant={row.status === 'active' ? 'default' : 'secondary'}
                >
                    {t(`admin.showcase.status_${row.status}`)}
                </Badge>
            ),
        },
        {
            key: 'updatedAt',
            header: t('admin.showcase.col_updated'),
            sortable: true,
            cell: (row) => (
                <span className="text-muted-foreground">
                    {formatDateTime(row.updatedAt)}
                </span>
            ),
        },
        {
            key: 'actions',
            header: t('admin.common.actions'),
            align: 'right',
            cell: (row) => (
                <div className="flex justify-end gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => openEdit(row)}
                        aria-label={t('admin.common.edit')}
                    >
                        <PencilIcon className="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => setDeleting(row)}
                        aria-label={t('admin.common.delete')}
                    >
                        <Trash2Icon className="size-4 text-destructive" />
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <AdminLayout breadcrumbs={[{ label: t('admin.nav.showcase') }]}>
            <Head title={t('admin.showcase.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('admin.showcase.title')}
                    description={t('admin.showcase.subtitle')}
                    actions={
                        <Button onClick={openCreate}>
                            <PlusIcon className="size-4" />
                            {t('admin.showcase.add')}
                        </Button>
                    }
                />

                <div className="relative max-w-xs">
                    <SearchIcon className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={search}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            setPage(1);
                        }}
                        placeholder={t('admin.common.search_placeholder')}
                        aria-label={t('admin.common.search_placeholder')}
                        className="pl-9"
                    />
                </div>

                <DataTable
                    columns={columns}
                    rows={pageRows}
                    getRowId={(row) => row.id}
                    caption={t('admin.showcase.title')}
                    sort={sort}
                    onSort={toggleSort}
                    empty={
                        search ? (
                            <EmptyState
                                icon={SearchIcon}
                                title={t('admin.common.no_results')}
                            />
                        ) : (
                            <EmptyState
                                icon={BlocksIcon}
                                title={t('admin.showcase.empty_title')}
                                description={t('admin.showcase.empty_body')}
                                action={
                                    <Button onClick={openCreate}>
                                        <PlusIcon className="size-4" />
                                        {t('admin.showcase.add')}
                                    </Button>
                                }
                            />
                        )
                    }
                />

                {filtered.length > PAGE_SIZE ? (
                    <Pager
                        current={current}
                        last={lastPage}
                        onPrev={() =>
                            setPage((value) => Math.max(1, value - 1))
                        }
                        onNext={() =>
                            setPage((value) => Math.min(lastPage, value + 1))
                        }
                        prevLabel={t('admin.common.prev')}
                        nextLabel={t('admin.common.next')}
                        statusLabel={t('admin.common.page_status', {
                            current,
                            last: lastPage,
                        })}
                    />
                ) : null}
            </div>

            <FormSheet
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                title={
                    editing
                        ? t('admin.showcase.edit_title')
                        : t('admin.showcase.new_title')
                }
                description={t('admin.showcase.form_desc')}
                submitLabel={t('admin.common.save')}
                cancelLabel={t('admin.common.cancel')}
                onSubmit={submit}
            >
                <div className="flex flex-col gap-2">
                    <Label htmlFor="item-name">
                        {t('admin.showcase.field_name')}
                    </Label>
                    <Input
                        id="item-name"
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        autoFocus
                    />
                </div>
                <div className="flex flex-col gap-2">
                    <Label htmlFor="item-type">
                        {t('admin.showcase.field_type')}
                    </Label>
                    <select
                        id="item-type"
                        value={type}
                        onChange={(event) =>
                            setType(event.target.value as ItemType)
                        }
                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <option value="room">
                            {t('admin.showcase.type_room')}
                        </option>
                        <option value="equipment">
                            {t('admin.showcase.type_equipment')}
                        </option>
                    </select>
                </div>
                <div className="flex flex-col gap-2">
                    <Label htmlFor="item-status">
                        {t('admin.showcase.field_status')}
                    </Label>
                    <select
                        id="item-status"
                        value={status}
                        onChange={(event) =>
                            setStatus(event.target.value as ItemStatus)
                        }
                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <option value="active">
                            {t('admin.showcase.status_active')}
                        </option>
                        <option value="inactive">
                            {t('admin.showcase.status_inactive')}
                        </option>
                    </select>
                </div>
            </FormSheet>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={t('admin.showcase.delete_title')}
                description={t('admin.showcase.delete_body', {
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
