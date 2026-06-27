import { usePage } from '@inertiajs/react';

/**
 * Resolve permission checks against the `auth.permissions` Inertia shared prop.
 * Mirrors the server-side gate; super-admins receive every permission code, so
 * this returns true for them across the board.
 */
export function usePermissions() {
    const { auth } = usePage().props;

    return function can(permission: string): boolean {
        return auth.permissions.includes(permission);
    };
}
