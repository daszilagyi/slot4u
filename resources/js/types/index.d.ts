import type { PageProps as InertiaPageProps } from '@inertiajs/core';

export type Translations = {
    [key: string]: string | Translations;
};

export type AuthUser = {
    id: number;
    name: string;
    email: string;
    is_staff: boolean;
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
    content_url?: string;
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

export type EventStatusValue = 'scheduled' | 'canceled';

export type EventItem = {
    id: number;
    service_id: number;
    service_name: string | null;
    staff_id: number | null;
    staff_name: string | null;
    room_id: number | null;
    room_name: string | null;
    starts_at: string;
    ends_at: string;
    capacity: number;
    booked_count: number;
    waitlist_enabled: boolean;
    status: EventStatusValue;
    is_recurring: boolean;
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

export type BookingStatusValue =
    | 'requested'
    | 'approved'
    | 'pending_payment'
    | 'confirmed'
    | 'completed'
    | 'canceled'
    | 'rejected'
    | 'no_show';

export type CustomerSummary = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    bookings_count: number;
    created_at: string | null;
};

export type CustomerBooking = {
    id: number;
    code: string;
    service: string | null;
    staff: string | null;
    status: BookingStatusValue;
    starts_at: string | null;
    price_minor: number;
    currency: string;
};

export type CustomerCard = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    created_at: string | null;
    bookings_count: number;
    completed_count: number;
    total_spend_minor: number;
    currency: string;
    recent_bookings: CustomerBooking[];
};

export type BookingSummary = {
    id: number;
    code: string;
    customer: string | null;
    service: string | null;
    staff: string | null;
    status: BookingStatusValue;
    booking_mode: BookingModeValue;
    starts_at: string | null;
    ends_at: string | null;
    price_minor: number;
    currency: string;
};

export type BookingHistoryEntry = {
    id: number;
    from_status: BookingStatusValue | null;
    to_status: BookingStatusValue;
    actor: string | null;
    created_at: string | null;
};

export type BookingDetail = {
    id: number;
    code: string;
    status: BookingStatusValue;
    booking_mode: BookingModeValue;
    customer: string | null;
    customer_email: string | null;
    service: string | null;
    staff: string | null;
    room: string | null;
    starts_at: string | null;
    ends_at: string | null;
    party_size: number;
    price_minor: number;
    currency: string;
    notes: string | null;
    cancel_reason: string | null;
    history: BookingHistoryEntry[];
};

export type BookingFilterOptions = {
    statuses: BookingStatusValue[];
    staff: { id: number; name: string }[];
    services: { id: number; name: string }[];
};

export type BookingFilters = {
    status: BookingStatusValue | null;
    staff_id: number | null;
    service_id: number | null;
    from: string | null;
    to: string | null;
};

export type PublicHomeService = {
    id: number;
    name: string;
    description: string | null;
    booking_mode: BookingModeValue;
    duration_minutes: number | null;
    price_minor: number;
    currency: string;
};

export type PublicHomeCategory = {
    id: number | null;
    name: string | null;
    services: PublicHomeService[];
};

export type PublicHomeLocation = {
    id: number;
    name: string;
    address: LocationAddress;
    phone: string | null;
};

export type PublicHomeProfile = {
    name: string;
    description: string | null;
    email: string | null;
    phone: string | null;
    address: LocationAddress;
    opening_hours: string | null;
    social: Record<string, string>;
};

export type PublicHomeBranding = {
    cover_url: string | null;
};

export type BookServiceOption = {
    id: number;
    name: string;
    booking_mode: BookingModeValue;
};

export type BookServiceDetail = {
    id: number;
    name: string;
    description: string | null;
    price_minor: number;
    currency: string;
    booking_mode: BookingModeValue;
    duration_minutes: number | null;
    /** Free-range resource_rental bounds (SLO-92); null otherwise. */
    min_duration_minutes: number | null;
    max_duration_minutes: number | null;
    /** no_time_slot only (SLO-101): digital / manual / downloadable, or null. */
    fulfillment_type: FulfillmentTypeValue | null;
};

export type BookSlot = {
    start: string;
    end: string;
    staff_id: number | null;
    room_id: number | null;
    time: string;
};

export type BookEvent = {
    id: number;
    starts_local: string | null;
    ends_time: string;
    staff: string | null;
    room: string | null;
    capacity: number;
    remaining: number;
    is_full: boolean;
    waitlist_available: boolean;
};

export type BookDay = {
    date: string;
    day: number;
    weekday: number;
    is_today: boolean;
    is_past: boolean;
    is_selected: boolean;
};

export type BookStaffOption = {
    id: number;
    name: string;
    location_ids: number[];
};

export type BookRoomOption = {
    id: number;
    name: string;
    location_id: number;
};

export type BookLocationOption = { id: number; name: string };

export type BookedBooking = {
    code: string;
    service: string | null;
    staff: string | null;
    status: BookingStatusValue;
    starts_at: string | null;
    starts_local: string | null;
    ends_local: string | null;
    content_url: string | null;
};

export type MyBooking = {
    id: number;
    code: string;
    service: string | null;
    staff: string | null;
    status: BookingStatusValue;
    starts_at: string | null;
    starts_local: string | null;
    ends_local: string | null;
    can_cancel: boolean;
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
