import type { PageProps as InertiaPageProps } from '@inertiajs/core';

export type Translations = {
    [key: string]: string | Translations;
};

declare module '@inertiajs/core' {
    interface PageProps {
        locale: string;
        translations: Translations;
    }
}

declare global {
    type AppPageProps<
        T extends Record<string, unknown> = Record<string, unknown>,
    > = T & InertiaPageProps;
}
