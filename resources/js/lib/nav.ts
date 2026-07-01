import {
    BlocksIcon,
    CalendarClockIcon,
    LayoutDashboardIcon,
    MapPinIcon,
    SettingsIcon,
    SparklesIcon,
    UsersIcon,
    type LucideIcon,
} from 'lucide-react';

export type NavItem = {
    key: string;
    /** i18n key resolved with the t() helper at render time. */
    labelKey: string;
    href: string;
    icon: LucideIcon;
    /** Tenant permission code gating visibility; undefined = always visible. */
    permission?: string;
    /** Whether the destination page exists yet; not-ready items render disabled. */
    ready: boolean;
};

/**
 * Tenant admin navigation (SLO-15). Permission codes mirror the Permission enum
 * (docs/03) so the menu shows only what the user may manage. The M2 CRUD
 * sections are listed but flagged not-ready until their issues (SLO-16+) land.
 */
export const navItems: NavItem[] = [
    {
        key: 'dashboard',
        labelKey: 'admin.nav.dashboard',
        href: '/dashboard',
        icon: LayoutDashboardIcon,
        ready: true,
    },
    {
        key: 'locations',
        labelKey: 'admin.nav.locations',
        href: '/locations',
        icon: MapPinIcon,
        permission: 'location.manage',
        ready: true,
    },
    {
        key: 'services',
        labelKey: 'admin.nav.services',
        href: '/services',
        icon: SparklesIcon,
        permission: 'service.manage',
        ready: false,
    },
    {
        key: 'staff',
        labelKey: 'admin.nav.staff',
        href: '/staff',
        icon: UsersIcon,
        permission: 'staff.manage',
        ready: false,
    },
    {
        key: 'schedule',
        labelKey: 'admin.nav.schedule',
        href: '/schedule',
        icon: CalendarClockIcon,
        permission: 'schedule.manage',
        ready: false,
    },
    {
        key: 'settings',
        labelKey: 'admin.nav.settings',
        href: '/settings',
        icon: SettingsIcon,
        permission: 'settings.edit',
        ready: false,
    },
    {
        key: 'showcase',
        labelKey: 'admin.nav.showcase',
        href: '/showcase',
        icon: BlocksIcon,
        ready: true,
    },
];
