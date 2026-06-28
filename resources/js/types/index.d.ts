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
    }
}

declare global {
    type AppPageProps<
        T extends Record<string, unknown> = Record<string, unknown>,
    > = T & InertiaPageProps;
}
