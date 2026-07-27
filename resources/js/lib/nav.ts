import {
    BlocksIcon,
    CalendarCheckIcon,
    CalendarClockIcon,
    CalendarPlusIcon,
    ContactIcon,
    GlobeIcon,
    LayoutDashboardIcon,
    MailIcon,
    MapPinIcon,
    ReceiptTextIcon,
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
    /** Tenant feature flag gating visibility; undefined = not feature-gated. */
    feature?: string;
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
        ready: true,
    },
    {
        key: 'staff',
        labelKey: 'admin.nav.staff',
        href: '/staff',
        icon: UsersIcon,
        permission: 'staff.manage',
        ready: true,
    },
    {
        key: 'schedule',
        labelKey: 'admin.nav.schedule',
        href: '/schedule',
        icon: CalendarClockIcon,
        permission: 'schedule.manage',
        ready: true,
    },
    {
        key: 'events',
        labelKey: 'admin.nav.events',
        href: '/events',
        icon: CalendarPlusIcon,
        permission: 'schedule.manage',
        ready: true,
    },
    {
        key: 'bookings',
        labelKey: 'admin.nav.bookings',
        href: '/bookings',
        icon: CalendarCheckIcon,
        permission: 'booking.view',
        ready: true,
    },
    {
        key: 'customers',
        labelKey: 'admin.nav.customers',
        href: '/customers',
        icon: ContactIcon,
        permission: 'customer.view',
        ready: true,
    },
    {
        key: 'billing',
        labelKey: 'admin.nav.billing',
        href: '/billing',
        icon: ReceiptTextIcon,
        permission: 'billing.view',
        ready: true,
    },
    {
        key: 'templates',
        labelKey: 'admin.nav.templates',
        href: '/settings/templates',
        icon: MailIcon,
        permission: 'template.manage',
        ready: true,
    },
    {
        key: 'domains',
        labelKey: 'admin.nav.domains',
        href: '/settings/domains',
        icon: GlobeIcon,
        permission: 'settings.edit',
        feature: 'feature_custom_domain',
        ready: true,
    },
    {
        key: 'settings',
        labelKey: 'admin.nav.settings',
        href: '/settings',
        icon: SettingsIcon,
        permission: 'settings.edit',
        ready: true,
    },
    {
        key: 'showcase',
        labelKey: 'admin.nav.showcase',
        href: '/showcase',
        icon: BlocksIcon,
        ready: true,
    },
];
