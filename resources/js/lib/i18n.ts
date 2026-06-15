import { usePage } from '@inertiajs/react';
import type { Translations } from '@/types';

function resolve(translations: Translations, key: string): string | undefined {
    const value = key
        .split('.')
        .reduce<string | Translations | undefined>((acc, segment) => {
            if (acc && typeof acc === 'object') {
                return acc[segment];
            }

            return undefined;
        }, translations);

    return typeof value === 'string' ? value : undefined;
}

function interpolate(
    message: string,
    replacements: Record<string, string | number>,
): string {
    return Object.entries(replacements).reduce(
        (acc, [token, replacement]) =>
            acc.replace(new RegExp(`:${token}`, 'g'), String(replacement)),
        message,
    );
}

/**
 * Translate a dot-notation key against the `translations` Inertia shared prop.
 * Falls back to the key itself when the translation is missing.
 */
export function useTranslations() {
    const { translations } = usePage().props;

    return function t(
        key: string,
        replacements: Record<string, string | number> = {},
    ): string {
        const message = resolve(translations, key);

        if (message === undefined) {
            return key;
        }

        return interpolate(message, replacements);
    };
}
