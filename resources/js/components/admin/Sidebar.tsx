import { Link, usePage } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { useTranslations } from '@/lib/i18n';
import { navItems } from '@/lib/nav';
import { usePermissions } from '@/lib/permissions';
import { cn } from '@/lib/utils';

/**
 * Tenant admin sidebar (SLO-15): tenant branding + permission-filtered nav.
 * Items the user lacks the permission for are omitted entirely; not-yet-built
 * sections render disabled with a "soon" badge instead of a dead link.
 */
export default function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
    const t = useTranslations();
    const can = usePermissions();
    const { props, url } = usePage();
    const tenant = props.tenant;

    const items = navItems.filter(
        (item) => !item.permission || can(item.permission),
    );

    return (
        <div className="flex h-full flex-col">
            <div className="flex items-center gap-3 px-4 py-4">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary text-sm font-semibold text-primary-foreground">
                    {tenant ? tenant.name.charAt(0).toUpperCase() : 'S'}
                </span>
                <div className="flex min-w-0 flex-col">
                    <span className="truncate text-sm font-semibold">
                        {tenant?.name ?? 'slot4u'}
                    </span>
                    <span className="truncate text-xs text-muted-foreground">
                        {t('admin.brand_tagline')}
                    </span>
                </div>
            </div>

            <nav
                aria-label={t('admin.nav.label')}
                className="flex flex-col gap-1 px-3"
            >
                {items.map((item) => {
                    const Icon = item.icon;

                    if (!item.ready) {
                        return (
                            <span
                                key={item.key}
                                aria-disabled="true"
                                className="flex cursor-not-allowed items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm text-muted-foreground/60"
                            >
                                <span className="flex items-center gap-3">
                                    <Icon className="size-4" />
                                    {t(item.labelKey)}
                                </span>
                                <Badge
                                    variant="outline"
                                    className="px-1.5 text-[10px]"
                                >
                                    {t('admin.nav.soon')}
                                </Badge>
                            </span>
                        );
                    }

                    const active =
                        url === item.href || url.startsWith(`${item.href}/`);

                    return (
                        <Link
                            key={item.key}
                            href={item.href}
                            onClick={onNavigate}
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                active
                                    ? 'bg-primary/10 text-primary'
                                    : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                            )}
                        >
                            <Icon className="size-4" />
                            {t(item.labelKey)}
                        </Link>
                    );
                })}
            </nav>
        </div>
    );
}
