import type { PageProps as InertiaPageProps } from '@inertiajs/core';

export type Translations = {
    [key: string]: string | Translations;
};

export type AuthUser = {
    id: number;
    name: string;
    email: string;
};

export type Auth = {
    user: AuthUser | null;
    permissions: string[];
};

export type ImpersonationState = {
    tenant: { id: number; name: string };
    stopUrl: string;
};

export type TenantIdentity = {
    name: string;
    slug: string;
    logo_url: string | null;
    primary_color: string;
};

export type RoomTypeValue = 'room' | 'equipment';

export type Room = {
    id: number;
    location_id: number;
    name: string;
    type: RoomTypeValue;
    capacity: number;
    description: string | null;
    active: boolean;
};

export type LocationAddress = {
    line: string | null;
    city: string | null;
    postal_code: string | null;
} | null;

export type Location = {
    id: number;
    name: string;
    address: LocationAddress;
    phone: string | null;
    sort_order: number;
    active: boolean;
    rooms: Room[];
};

export type ResourceLimit = { used: number; max: number | null };

export type StaffMember = {
    id: number;
    name: string;
    title: string | null;
    bio: string | null;
    photo: string | null;
    color: string;
    active: boolean;
    user: { email: string } | null;
    location_ids: number[];
};

export type StaffProfile = {
    id: number;
    name: string;
    title: string | null;
    bio: string | null;
    color: string;
};

export type BookingModeValue =
    | 'no_time_slot'
    | 'duration_based'
    | 'event_based'
    | 'resource_rental'
    | 'quote_request';

export type ServiceCategory = {
    id: number;
    name: string;
    sort_order: number;
};

export type FulfillmentTypeValue = 'digital' | 'manual' | 'downloadable';

export type ServiceSettings = {
    fulfillment_type?: FulfillmentTypeValue;
    min_duration_minutes?: number;
    max_duration_minutes?: number;
    deposit_minor?: number;
    quote_fields?: string[];
} | null;

export type Service = {
    id: number;
    category_id: number | null;
    name: string;
    description: string | null;
    booking_mode: BookingModeValue;
    duration_minutes: number | null;
    buffer_before_minutes: number;
    buffer_after_minutes: number;
    price_minor: number;
    currency: string;
    capacity: number | null;
    requires_staff: boolean;
    requires_room: boolean;
    requires_approval: boolean;
    waitlist_enabled: boolean;
    online_payment_required: boolean;
    settings: ServiceSettings;
    active: boolean;
    staff_ids: number[];
    room_ids: number[];
};

export type AssignableStaff = { id: number; name: string };
export type AssignableRoom = {
    id: number;
    name: string;
    location_name: string | null;
};

export type SchedulableTypeValue = 'staff' | 'room';

export type Schedulable = {
    type: SchedulableTypeValue;
    id: number;
    name: string;
    location_name: string | null;
    /** Locations this resource may scope a band to (SLO-51). */
    location_ids: number[];
};

export type ScheduleBand = {
    id: number;
    schedulable_type: SchedulableTypeValue;
    schedulable_id: number;
    location_id: number | null;
    day_of_week: number;
    start_time: string;
    end_time: string;
    valid_from: string | null;
    valid_until: string | null;
};

export type ScheduleExceptionTypeValue = 'off' | 'extra';

export type ScheduleExceptionEntry = {
    id: number;
    schedulable_type: SchedulableTypeValue;
    schedulable_id: number;
    date: string;
    start_time: string | null;
    end_time: string | null;
    type: ScheduleExceptionTypeValue;
    note: string | null;
};

export type ScheduleConflict = {
    id: number;
    code: string | null;
    starts_at: string;
};

export type TenantStatusValue = 'trial' | 'active' | 'suspended' | 'archived';

export type TenantSummary = {
    id: number;
    name: string;
    slug: string;
    status: TenantStatusValue;
    trial_ends_at: string | null;
    users_count: number;
    archived: boolean;
    created_at: string | null;
};

export type AuditLogEntry = {
    id: number;
    action: string;
    actor: { id: number; name: string; email: string } | null;
    tenant: { id: number; name: string; slug: string } | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    ip_address: string | null;
    created_at: string | null;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginator<T> = {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    total: number;
};

declare module '@inertiajs/core' {
    interface PageProps {
        locale: string;
        translations: Translations;
        auth: Auth;
        features: string[];
        status: string | null;
        impersonation: ImpersonationState | null;
        tenant: TenantIdentity | null;
    }
}

declare global {
    type AppPageProps<
        T extends Record<string, unknown> = Record<string, unknown>,
    > = T & InertiaPageProps;
}
