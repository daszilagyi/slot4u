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

/**
 * Read a whole SUBTREE of the translations, not a single string.
 *
 * ⚠️ `t()` deliberately returns only strings — a key that resolves to a branch
 * is a bug at every one of its call sites. The vertical landings (SLO-198) are
 * the exception that earns this second door: their entire page content is a
 * structured block under `verticals.{slug}`, lists included, and the whole point
 * is that adding the next trade touches no component. Handing that block to the
 * page as a second Inertia prop would have shipped it twice, since the
 * translations tree already carries it.
 *
 * Returns null when the key is missing or names a plain string, so a caller can
 * render nothing instead of a page of dotted keys. The cast is the caller's:
 * the shape of a branch is known where it is used, not here.
 */
export function useTranslationTree() {
    const { translations } = usePage().props;

    return function tree<T>(key: string): T | null {
        const value = key
            .split('.')
            .reduce<unknown>(
                (acc, segment) =>
                    acc && typeof acc === 'object'
                        ? (acc as Record<string, unknown>)[segment]
                        : undefined,
                translations,
            );

        return value !== null && typeof value === 'object' ? (value as T) : null;
    };
}
