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
    id: number;
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

/**
 * The booking abilities that decide which quick actions a booking row or calendar
 * card offers (SLO-85, SLO-136). Shared so the list and the calendar can never
 * drift into two different answers — see `lib/bookingActions`.
 */
export type BookingAbilities = {
    /** `booking.edit` — complete, mark no-show, reschedule. */
    edit: boolean;
    /** `booking.cancel` — a distinct permission from edit (docs/03). */
    cancel: boolean;
    /** `booking.approve` AND `feature_approval_flow` (docs/03, docs/04 §5). */
    approve: boolean;
};

export type BookingSummary = {
    id: number;
    code: string;
    customer: string | null;
    /** Booked without an account (SLO-128) — `customer` is the guest's own name. */
    is_guest: boolean;
    service: string | null;
    staff: string | null;
    status: BookingStatusValue;
    booking_mode: BookingModeValue;
    starts_at: string | null;
    ends_at: string | null;
    price_minor: number;
    currency: string;
};

/** A booking as the admin calendar carries it (SLO-44). */
export type CalendarBooking = {
    id: number;
    code: string;
    customer: string | null;
    /** Booked without an account (SLO-128) — `customer` is the guest's own name. */
    is_guest: boolean;
    service: string | null;
    staff: string | null;
    room: string | null;
    status: BookingStatusValue;
    booking_mode: BookingModeValue;
    starts_at: string | null;
    ends_at: string | null;
    party_size: number;
};

/** A booking placed on the calendar grid. */
export type CalendarEvent = CalendarBooking & {
    /** Tenant-local day (YYYY-MM-DD) the event is drawn on. */
    date: string;
    /** Which column it belongs to: the date in week view, `staff-3` / `room-none` in day view. */
    column_key: string;
    /** Wall-clock minutes from tenant-local midnight — NOT elapsed minutes (DST). */
    start_minute: number;
    end_minute: number;
    /**
     * Whether this card may be dragged to another slot (SLO-44): a time-slot mode
     * that is not terminal. The same rule RescheduleBooking enforces, so the grid
     * never offers a move the server would reject.
     */
    movable: boolean;
};

export type CalendarColumn = {
    key: string;
    /** Day view: the resource name. Week view: the ISO weekday number to localize. */
    label: string;
    sublabel: string | null;
    date: string | null;
    /**
     * What a drop on this column reassigns the booking to — the staff or room in the
     * grouped day view. `null` in week view (the day moves, not the resource) and on
     * the "unassigned" column, which is therefore not a drop target.
     */
    resource_id: number | null;
};

export type BookingCalendar = {
    view: 'day' | 'week';
    date: string;
    range_start: string;
    range_end: string;
    prev_date: string;
    next_date: string;
    today: string;
    timezone: string;
    window_start_minute: number;
    window_end_minute: number;
    columns: CalendarColumn[];
    events: CalendarEvent[];
    /** In range but unplaceable — no start time (the `no_time_slot` mode). */
    unscheduled: CalendarBooking[];
};

export type CalendarFilters = {
    view: 'day' | 'week';
    group: 'staff' | 'room';
    date: string | null;
    staff_id: number | null;
    room_id: number | null;
    service_id: number | null;
};

/** A service in the calendar's dropdowns (SLO-44 filter, SLO-136 quick booking). */
export type CalendarServiceOption = {
    id: number;
    name: string;
    booking_mode: BookingModeValue;
    /** Fixed length in minutes; `null` for a variable-duration rental (docs/04 §4). */
    duration_minutes: number | null;
    /** Whether an empty-slot quick booking may use it — the time-slot modes only. */
    bookable: boolean;
};

/** A customer in the quick-booking picker (SLO-136), loaded on demand. */
export type CalendarCustomerOption = {
    id: number;
    name: string;
    email: string | null;
};

export type CalendarOptions = {
    staff: { id: number; name: string }[];
    rooms: { id: number; name: string }[];
    services: CalendarServiceOption[];
};

/**
 * What the actor may do on the calendar (SLO-44, SLO-136). The endpoints enforce
 * every one of these themselves; the flags only stop the grid from offering a
 * button the server would refuse.
 */
export type CalendarAbilities = BookingAbilities & {
    /** `booking.create` — required for the quick booking on an empty slot. */
    create: boolean;
};

/** A booking row on the dashboard's agenda / latest panels (SLO-43). */
export type DashboardBooking = {
    id: number;
    code: string;
    customer: string | null;
    /** Booked without an account (SLO-128) — `customer` is the guest's own name. */
    is_guest: boolean;
    service: string | null;
    staff: string | null;
    status: BookingStatusValue;
    starts_at: string | null;
    created_at: string | null;
    price_minor: number;
    currency: string;
};

/**
 * The tenant bento dashboard's read model (SLO-43). A `null` block means the
 * actor has no permission for it, which is why the numbers are nullable too —
 * the page drops the tile rather than showing a zero it may not know.
 */
export type TenantDashboard = {
    /** Tenant-local "today" (YYYY-MM-DD) every day figure covers. */
    date: string;
    /** Tenant-local month (YYYY-MM) the calendar covers. */
    calendar_month: string;
    timezone: string;
    currency: string;
    bookings_today: number | null;
    confirmed_today: number | null;
    revenue_today_minor: number | null;
    pending_approval: number | null;
    pending_payment: number | null;
    customers_total: number | null;
    customers_new_this_month: number | null;
    agenda: DashboardBooking[] | null;
    recent: DashboardBooking[] | null;
    calendar: { date: string; count: number }[] | null;
};

/**
 * Headline figures of one reporting period (SLO-137). Rates are basis points and
 * money is minor units, like everywhere else. A `null` rate means the denominator
 * was zero — "no data", which is not the same answer as 0%.
 */
export type ReportTotals = {
    bookings: number;
    /** confirmed + completed + no_show — the commission base (docs/10 §3.1). */
    realized: number;
    canceled: number;
    no_show: number;
    revenue_minor: number;
    customers: number;
    average_value_minor: number | null;
    no_show_rate_bps: number | null;
    cancel_rate_bps: number | null;
};

/** One row of the per-resource activity panels (staff, rooms). */
export type ReportResourceRow = {
    id: number;
    name: string;
    bookings: number;
    revenue_minor: number;
    booked_minutes: number;
    scheduled_minutes: number;
    /** null when the resource has no open hours at all — the ratio is undefined. */
    utilization_bps: number | null;
};

/** The tenant statistics module's read model (SLO-137, docs/05 M7). */
export type TenantReport = {
    preset: string;
    /** Inclusive tenant-local date bounds (YYYY-MM-DD). */
    from: string;
    to: string;
    previous_from: string;
    previous_to: string;
    timezone: string;
    currency: string;
    totals: ReportTotals;
    previous_totals: ReportTotals;
    series: { date: string; revenue_minor: number; bookings: number }[];
    by_service: {
        id: number;
        name: string | null;
        bookings: number;
        revenue_minor: number;
    }[];
    by_staff: ReportResourceRow[];
    by_room: ReportResourceRow[];
    top_customers: {
        name: string;
        is_guest: boolean;
        bookings: number;
        spend_minor: number;
    }[];
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
    customer_phone: string | null;
    /** Booked without an account (SLO-128) — the contact fields are the guest's own. */
    is_guest: boolean;
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
    /** Not a filter: the "offer another time" form may move the room (SLO-144). */
    rooms: { id: number; name: string }[];
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
    /**
     * Whether the guest may cancel it themselves right now (SLO-129) — decided
     * by the server from the same rule the endpoint enforces, so the button is
     * never offered for something that would be refused.
     */
    can_cancel: boolean;
    /** Still awaiting online payment and the integration is on (SLO-130). */
    payable: boolean;
    price_minor: number;
    currency: string;
    /** Charged online now — the deposit for a rental that asks for one (SLO-131). */
    due_minor: number;
    /** When the unpaid booking releases its slot (tenant-local), if payable. */
    payment_deadline_local: string | null;
};

/** A refund against a customer payment (SLO-131). */
export type BookingRefund = {
    id: number;
    status: 'pending' | 'completed' | 'failed';
    amount_minor: number;
    currency: string;
    reason: string | null;
    refunded_at: string | null;
};

/** One online checkout attempt on a booking, with its refunds (SLO-130/131). */
export type BookingPayment = {
    id: number;
    provider: string;
    status: 'pending' | 'paid' | 'failed' | 'refunded';
    amount_minor: number;
    currency: string;
    paid_at: string | null;
    refunds: BookingRefund[];
};

/** The sandbox gateway's checkout screen (SLO-130, non-production only). */
export type SandboxCheckoutPayment = {
    reference: string | null;
    amount_minor: number;
    currency: string;
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
    can_reschedule: boolean;
};

export type RescheduleBooking = {
    id: number;
    code: string;
    service: string;
    booking_mode: BookingModeValue;
    duration_minutes: number | null;
    min_duration_minutes: number | null;
    max_duration_minutes: number | null;
    current_local: string | null;
};

export type WaitlistStatusValue =
    | 'waiting'
    | 'offered'
    | 'converted'
    | 'expired';

export type MyWaitlistEntry = {
    id: number;
    service_name: string | null;
    event_starts_local: string | null;
    position: number;
    status: WaitlistStatusValue;
    offered_until_local: string | null;
    party_size: number;
};

export type QuoteRequestStatusValue =
    | 'new'
    | 'in_progress'
    | 'quoted'
    | 'accepted'
    | 'rejected';

/** One of the customer's own invoices (SLO-133). */
export type MyInvoice = {
    id: number;
    number: string | null;
    booking_code: string | null;
    service_name: string | null;
    amount_minor: number;
    currency: string;
    status: 'issued' | 'storno';
    issued_local: string | null;
    has_pdf: boolean;
};

/** The customer invoice shown on the admin booking page (SLO-133). */
export type BookingInvoice = {
    id: number;
    number: string | null;
    status: 'pending' | 'issued' | 'storno' | 'failed';
    amount_minor: number;
    currency: string;
    issued_at: string | null;
    error: string | null;
    can_retry: boolean;
    has_pdf: boolean;
};

/** One of the customer's own online payments, with its refunds (SLO-132). */
export type MyPayment = {
    id: number;
    booking_code: string | null;
    service_name: string | null;
    booking_starts_local: string | null;
    amount_minor: number;
    currency: string;
    status: 'pending' | 'paid' | 'failed' | 'refunded';
    paid_local: string | null;
    created_local: string | null;
    refunds: {
        id: number;
        amount_minor: number;
        currency: string;
        status: 'pending' | 'completed';
        refunded_local: string | null;
    }[];
};

export type MyQuoteRequest = {
    id: number;
    service_name: string | null;
    status: QuoteRequestStatusValue;
    price_minor: number | null;
    currency: string | null;
    valid_until_local: string | null;
    created_local: string | null;
};

/** One legal document in force, as shared to every page (SLO-161). */
type LegalDocumentSummary = {
    id: number;
    type: 'terms' | 'privacy';
    version: string;
    title: string;
    href: string;
};

/**
 * The documents this host may ask a visitor to accept, plus the id set the form
 * has to submit back. An empty `documents` means the scope has published
 * nothing, and no acceptance is asked for anywhere.
 */
type LegalSharedProps = {
    documents: LegalDocumentSummary[];
    ids: number[];
};

/**
 * The visitor's cookie decision (SLO-165). `decided` is separate from the
 * categories because "declined analytics" and "has not been asked" must render
 * differently — one shows the banner, the other does not.
 */
type ConsentSharedProps = {
    decided: boolean;
    categories: Record<string, boolean>;
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
        legal: LegalSharedProps | null;
        consent: ConsentSharedProps | null;
    }
}

declare global {
    type AppPageProps<
        T extends Record<string, unknown> = Record<string, unknown>,
    > = T & InertiaPageProps;
}
