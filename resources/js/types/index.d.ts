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
