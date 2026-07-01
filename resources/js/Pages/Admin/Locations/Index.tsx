import { Head, router, useForm } from '@inertiajs/react';
import { MapPinIcon, PencilIcon, PlusIcon, Trash2Icon } from 'lucide-react';
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
import type { Location, ResourceLimit, Room, RoomTypeValue } from '@/types';

type IndexProps = {
    locations: Location[];
    limits: { locations: ResourceLimit; rooms: ResourceLimit };
    roomTypes: RoomTypeValue[];
};

function atLimit(limit: ResourceLimit): boolean {
    return limit.max !== null && limit.used >= limit.max;
}

function formatAddress(address: Location['address']): string | null {
    if (!address) {
        return null;
    }

    const parts = [address.postal_code, address.city, address.line].filter(
        Boolean,
    );

    return parts.length > 0 ? parts.join(' ') : null;
}

export default function LocationsIndex({
    locations,
    limits,
    roomTypes,
}: IndexProps) {
    const t = useTranslations();

    const [locationSheetOpen, setLocationSheetOpen] = useState(false);
    const [editingLocation, setEditingLocation] = useState<Location | null>(
        null,
    );
    const [deletingLocation, setDeletingLocation] = useState<Location | null>(
        null,
    );

    const [roomSheetOpen, setRoomSheetOpen] = useState(false);
    const [roomLocation, setRoomLocation] = useState<Location | null>(null);
    const [editingRoom, setEditingRoom] = useState<Room | null>(null);
    const [deletingRoom, setDeletingRoom] = useState<Room | null>(null);

    const locationForm = useForm({
        name: '',
        address_line: '',
        address_city: '',
        address_postal: '',
        phone: '',
        sort_order: 0,
        active: true,
    });

    const roomForm = useForm({
        name: '',
        type: 'room' as RoomTypeValue,
        capacity: 1,
        description: '',
        active: true,
    });

    const locationsFull = atLimit(limits.locations);
    const roomsFull = atLimit(limits.rooms);

    function openCreateLocation() {
        setEditingLocation(null);
        locationForm.clearErrors();
        locationForm.setDefaults({
            name: '',
            address_line: '',
            address_city: '',
            address_postal: '',
            phone: '',
            sort_order: 0,
            active: true,
        });
        locationForm.reset();
        setLocationSheetOpen(true);
    }

    function openEditLocation(location: Location) {
        setEditingLocation(location);
        locationForm.clearErrors();
        locationForm.setData({
            name: location.name,
            address_line: location.address?.line ?? '',
            address_city: location.address?.city ?? '',
            address_postal: location.address?.postal_code ?? '',
            phone: location.phone ?? '',
            sort_order: location.sort_order,
            active: location.active,
        });
        setLocationSheetOpen(true);
    }

    function submitLocation(event: FormEvent) {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(
                    editingLocation
                        ? t('admin.locations.updated')
                        : t('admin.locations.created'),
                );
                setLocationSheetOpen(false);
            },
        };

        locationForm.transform((data) => ({
            name: data.name,
            address: {
                line: data.address_line || null,
                city: data.address_city || null,
                postal_code: data.address_postal || null,
            },
            phone: data.phone || null,
            sort_order: data.sort_order,
            active: data.active,
        }));

        if (editingLocation) {
            locationForm.put(`/locations/${editingLocation.id}`, options);
        } else {
            locationForm.post('/locations', options);
        }
    }

    function openCreateRoom(location: Location) {
        setRoomLocation(location);
        setEditingRoom(null);
        roomForm.clearErrors();
        roomForm.setDefaults({
            name: '',
            type: 'room',
            capacity: 1,
            description: '',
            active: true,
        });
        roomForm.reset();
        setRoomSheetOpen(true);
    }

    function openEditRoom(location: Location, room: Room) {
        setRoomLocation(location);
        setEditingRoom(room);
        roomForm.clearErrors();
        roomForm.setData({
            name: room.name,
            type: room.type,
            capacity: room.capacity,
            description: room.description ?? '',
            active: room.active,
        });
        setRoomSheetOpen(true);
    }

    function submitRoom(event: FormEvent) {
        event.preventDefault();

        if (!roomLocation) {
            return;
        }

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(
                    editingRoom
                        ? t('admin.locations.rooms.updated')
                        : t('admin.locations.rooms.created'),
                );
                setRoomSheetOpen(false);
            },
        };

        if (editingRoom) {
            roomForm.put(`/rooms/${editingRoom.id}`, options);
        } else {
            roomForm.post(`/locations/${roomLocation.id}/rooms`, options);
        }
    }

    function confirmDeleteLocation() {
        if (!deletingLocation) {
            return;
        }

        router.delete(`/locations/${deletingLocation.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success(t('admin.locations.deleted')),
            onError: (errors) =>
                toast.error(
                    errors.delete ??
                        t('admin.locations.error.location_has_rooms'),
                ),
        });
    }

    function confirmDeleteRoom() {
        if (!deletingRoom) {
            return;
        }

        router.delete(`/rooms/${deletingRoom.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success(t('admin.locations.rooms.deleted')),
            onError: (errors) =>
                toast.error(
                    errors.delete ??
                        t('admin.locations.error.room_has_bookings'),
                ),
        });
    }

    return (
        <AdminLayout breadcrumbs={[{ label: t('admin.locations.title') }]}>
            <Head title={t('admin.locations.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('admin.locations.title')}
                    description={t('admin.locations.subtitle')}
                    actions={
                        <div className="flex items-center gap-3">
                            <Badge variant="outline">
                                {t('admin.locations.limit_badge', {
                                    used: limits.locations.used,
                                    max: limits.locations.max ?? '∞',
                                })}
                            </Badge>
                            <Button
                                onClick={openCreateLocation}
                                disabled={locationsFull}
                                title={
                                    locationsFull
                                        ? t('admin.locations.limit_at_max')
                                        : undefined
                                }
                            >
                                <PlusIcon className="size-4" />
                                {t('admin.locations.add')}
                            </Button>
                        </div>
                    }
                />

                {locations.length === 0 ? (
                    <EmptyState
                        icon={MapPinIcon}
                        title={t('admin.locations.empty_title')}
                        description={t('admin.locations.empty_body')}
                        action={
                            <Button
                                onClick={openCreateLocation}
                                disabled={locationsFull}
                                title={
                                    locationsFull
                                        ? t('admin.locations.limit_at_max')
                                        : undefined
                                }
                            >
                                <PlusIcon className="size-4" />
                                {t('admin.locations.add')}
                            </Button>
                        }
                    />
                ) : (
                    <div className="flex flex-col gap-4">
                        {locations.map((location) => (
                            <section
                                key={location.id}
                                className="rounded-2xl border border-border bg-card p-5"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="flex flex-col gap-1">
                                        <div className="flex items-center gap-2">
                                            <h2 className="text-lg font-semibold">
                                                {location.name}
                                            </h2>
                                            {location.active ? null : (
                                                <Badge variant="secondary">
                                                    {t(
                                                        'admin.locations.inactive',
                                                    )}
                                                </Badge>
                                            )}
                                        </div>
                                        {formatAddress(location.address) ? (
                                            <p className="text-sm text-muted-foreground">
                                                {formatAddress(
                                                    location.address,
                                                )}
                                            </p>
                                        ) : null}
                                        {location.phone ? (
                                            <p className="text-sm text-muted-foreground">
                                                {location.phone}
                                            </p>
                                        ) : null}
                                    </div>
                                    <div className="flex gap-1">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            onClick={() =>
                                                openEditLocation(location)
                                            }
                                            aria-label={t('admin.common.edit')}
                                        >
                                            <PencilIcon className="size-4" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            onClick={() =>
                                                setDeletingLocation(location)
                                            }
                                            aria-label={t(
                                                'admin.common.delete',
                                            )}
                                        >
                                            <Trash2Icon className="size-4 text-destructive" />
                                        </Button>
                                    </div>
                                </div>

                                <div className="mt-4 border-t border-border pt-4">
                                    <div className="mb-3 flex items-center justify-between">
                                        <h3 className="text-sm font-medium text-muted-foreground">
                                            {t('admin.locations.rooms.title')}
                                        </h3>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                openCreateRoom(location)
                                            }
                                            disabled={roomsFull}
                                            title={
                                                roomsFull
                                                    ? t(
                                                          'admin.locations.limit_at_max',
                                                      )
                                                    : undefined
                                            }
                                        >
                                            <PlusIcon className="size-4" />
                                            {t('admin.locations.rooms.add')}
                                        </Button>
                                    </div>

                                    {location.rooms.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            {t('admin.locations.rooms.empty')}
                                        </p>
                                    ) : (
                                        <ul className="flex flex-col divide-y divide-border">
                                            {location.rooms.map((room) => (
                                                <li
                                                    key={room.id}
                                                    className="flex flex-wrap items-center justify-between gap-2 py-2.5"
                                                >
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="font-medium">
                                                            {room.name}
                                                        </span>
                                                        <Badge variant="outline">
                                                            {t(
                                                                `admin.locations.rooms.type_${room.type}`,
                                                            )}
                                                        </Badge>
                                                        <span className="text-sm text-muted-foreground">
                                                            {t(
                                                                'admin.locations.rooms.capacity_label',
                                                                {
                                                                    count: room.capacity,
                                                                },
                                                            )}
                                                        </span>
                                                        {room.active ? null : (
                                                            <Badge variant="secondary">
                                                                {t(
                                                                    'admin.locations.inactive',
                                                                )}
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    <div className="flex gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() =>
                                                                openEditRoom(
                                                                    location,
                                                                    room,
                                                                )
                                                            }
                                                            aria-label={t(
                                                                'admin.common.edit',
                                                            )}
                                                        >
                                                            <PencilIcon className="size-4" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() =>
                                                                setDeletingRoom(
                                                                    room,
                                                                )
                                                            }
                                                            aria-label={t(
                                                                'admin.common.delete',
                                                            )}
                                                        >
                                                            <Trash2Icon className="size-4 text-destructive" />
                                                        </Button>
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            </section>
                        ))}
                    </div>
                )}
            </div>

            {/* Location create/edit */}
            <FormSheet
                open={locationSheetOpen}
                onOpenChange={setLocationSheetOpen}
                title={
                    editingLocation
                        ? t('admin.locations.edit_title')
                        : t('admin.locations.new_title')
                }
                description={t('admin.locations.form_desc')}
                submitLabel={t('admin.common.save')}
                cancelLabel={t('admin.common.cancel')}
                onSubmit={submitLocation}
                submitting={locationForm.processing}
            >
                <div className="flex flex-col gap-2">
                    <Label htmlFor="loc-name">
                        {t('admin.locations.field.name')}
                    </Label>
                    <Input
                        id="loc-name"
                        value={locationForm.data.name}
                        onChange={(event) =>
                            locationForm.setData('name', event.target.value)
                        }
                        autoFocus
                    />
                    {locationForm.errors.name ? (
                        <p className="text-sm text-destructive">
                            {locationForm.errors.name}
                        </p>
                    ) : null}
                </div>
                <div className="flex flex-col gap-2">
                    <Label htmlFor="loc-line">
                        {t('admin.locations.field.address_line')}
                    </Label>
                    <Input
                        id="loc-line"
                        value={locationForm.data.address_line}
                        onChange={(event) =>
                            locationForm.setData(
                                'address_line',
                                event.target.value,
                            )
                        }
                    />
                </div>
                <div className="flex gap-3">
                    <div className="flex flex-1 flex-col gap-2">
                        <Label htmlFor="loc-postal">
                            {t('admin.locations.field.address_postal')}
                        </Label>
                        <Input
                            id="loc-postal"
                            value={locationForm.data.address_postal}
                            onChange={(event) =>
                                locationForm.setData(
                                    'address_postal',
                                    event.target.value,
                                )
                            }
                        />
                    </div>
                    <div className="flex flex-[2] flex-col gap-2">
                        <Label htmlFor="loc-city">
                            {t('admin.locations.field.address_city')}
                        </Label>
                        <Input
                            id="loc-city"
                            value={locationForm.data.address_city}
                            onChange={(event) =>
                                locationForm.setData(
                                    'address_city',
                                    event.target.value,
                                )
                            }
                        />
                    </div>
                </div>
                <div className="flex flex-col gap-2">
                    <Label htmlFor="loc-phone">
                        {t('admin.locations.field.phone')}
                    </Label>
                    <Input
                        id="loc-phone"
                        value={locationForm.data.phone}
                        onChange={(event) =>
                            locationForm.setData('phone', event.target.value)
                        }
                    />
                </div>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={locationForm.data.active}
                        onChange={(event) =>
                            locationForm.setData('active', event.target.checked)
                        }
                        className="size-4 rounded border-input"
                    />
                    {t('admin.locations.field.active')}
                </label>
            </FormSheet>

            {/* Room create/edit */}
            <FormSheet
                open={roomSheetOpen}
                onOpenChange={setRoomSheetOpen}
                title={
                    editingRoom
                        ? t('admin.locations.rooms.edit_title')
                        : t('admin.locations.rooms.new_title')
                }
                description={t('admin.locations.rooms.form_desc')}
                submitLabel={t('admin.common.save')}
                cancelLabel={t('admin.common.cancel')}
                onSubmit={submitRoom}
                submitting={roomForm.processing}
            >
                <div className="flex flex-col gap-2">
                    <Label htmlFor="room-name">
                        {t('admin.locations.rooms.field.name')}
                    </Label>
                    <Input
                        id="room-name"
                        value={roomForm.data.name}
                        onChange={(event) =>
                            roomForm.setData('name', event.target.value)
                        }
                        autoFocus
                    />
                    {roomForm.errors.name ? (
                        <p className="text-sm text-destructive">
                            {roomForm.errors.name}
                        </p>
                    ) : null}
                </div>
                <div className="flex gap-3">
                    <div className="flex flex-1 flex-col gap-2">
                        <Label htmlFor="room-type">
                            {t('admin.locations.rooms.field.type')}
                        </Label>
                        <select
                            id="room-type"
                            value={roomForm.data.type}
                            onChange={(event) =>
                                roomForm.setData(
                                    'type',
                                    event.target.value as RoomTypeValue,
                                )
                            }
                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            {roomTypes.map((type) => (
                                <option key={type} value={type}>
                                    {t(`admin.locations.rooms.type_${type}`)}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex flex-1 flex-col gap-2">
                        <Label htmlFor="room-capacity">
                            {t('admin.locations.rooms.field.capacity')}
                        </Label>
                        <Input
                            id="room-capacity"
                            type="number"
                            min={1}
                            value={roomForm.data.capacity}
                            onChange={(event) =>
                                roomForm.setData(
                                    'capacity',
                                    Number(event.target.value),
                                )
                            }
                        />
                        {roomForm.errors.capacity ? (
                            <p className="text-sm text-destructive">
                                {roomForm.errors.capacity}
                            </p>
                        ) : null}
                    </div>
                </div>
                <div className="flex flex-col gap-2">
                    <Label htmlFor="room-desc">
                        {t('admin.locations.rooms.field.description')}
                    </Label>
                    <Input
                        id="room-desc"
                        value={roomForm.data.description}
                        onChange={(event) =>
                            roomForm.setData('description', event.target.value)
                        }
                    />
                </div>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={roomForm.data.active}
                        onChange={(event) =>
                            roomForm.setData('active', event.target.checked)
                        }
                        className="size-4 rounded border-input"
                    />
                    {t('admin.locations.rooms.field.active')}
                </label>
            </FormSheet>

            <ConfirmDialog
                open={deletingLocation !== null}
                onOpenChange={(open) => !open && setDeletingLocation(null)}
                title={t('admin.locations.delete_title')}
                description={t('admin.locations.delete_body', {
                    name: deletingLocation?.name ?? '',
                })}
                confirmLabel={t('admin.common.delete')}
                cancelLabel={t('admin.common.cancel')}
                onConfirm={confirmDeleteLocation}
                destructive
            />

            <ConfirmDialog
                open={deletingRoom !== null}
                onOpenChange={(open) => !open && setDeletingRoom(null)}
                title={t('admin.locations.rooms.delete_title')}
                description={t('admin.locations.rooms.delete_body', {
                    name: deletingRoom?.name ?? '',
                })}
                confirmLabel={t('admin.common.delete')}
                cancelLabel={t('admin.common.cancel')}
                onConfirm={confirmDeleteRoom}
                destructive
            />
        </AdminLayout>
    );
}
