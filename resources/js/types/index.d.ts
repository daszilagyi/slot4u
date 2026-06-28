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
