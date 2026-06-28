import { usePage } from '@inertiajs/react';

/**
 * Resolve feature-flag checks against the `features` Inertia shared prop, which
 * lists the feature codes enabled for the current tenant. Mirrors the
 * server-side EnsureFeatureEnabled middleware; outside tenant context the list
 * is empty, so every check returns false.
 */
export function useFeatures() {
    const { features } = usePage().props;

    return function feature(code: string): boolean {
        // Defensive default: a partial Inertia reload may omit this prop.
        return (features ?? []).includes(code);
    };
}
