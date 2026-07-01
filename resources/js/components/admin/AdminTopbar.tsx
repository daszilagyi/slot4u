import { Link, router, usePage } from '@inertiajs/react';
import { LogOutIcon, MenuIcon } from 'lucide-react';

import ThemeToggle from '@/components/ThemeToggle';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTranslations } from '@/lib/i18n';

export type Breadcrumb = { label: string; href?: string };

/** Admin topbar (SLO-15): mobile menu trigger, breadcrumb, theme toggle, user menu. */
export default function AdminTopbar({
    breadcrumbs,
    onOpenMenu,
}: {
    breadcrumbs?: Breadcrumb[];
    onOpenMenu: () => void;
}) {
    const t = useTranslations();
    const { auth } = usePage().props;
    const user = auth.user;
    const initials = user ? user.name.slice(0, 2).toUpperCase() : '';

    return (
        <header className="sticky top-0 z-30 flex h-14 items-center gap-2 border-b border-border bg-background/80 px-4 backdrop-blur">
            <Button
                variant="ghost"
                size="icon"
                className="lg:hidden"
                onClick={onOpenMenu}
                aria-label={t('admin.topbar.open_menu')}
            >
                <MenuIcon />
            </Button>

            {breadcrumbs && breadcrumbs.length > 0 ? (
                <nav
                    aria-label={t('admin.breadcrumb')}
                    className="min-w-0 flex-1"
                >
                    <ol className="flex items-center gap-1.5 text-sm text-muted-foreground">
                        {breadcrumbs.map((crumb, index) => (
                            <li
                                key={`${crumb.label}-${index}`}
                                className="flex min-w-0 items-center gap-1.5"
                            >
                                {index > 0 ? (
                                    <span aria-hidden="true">/</span>
                                ) : null}
                                {crumb.href ? (
                                    <Link
                                        href={crumb.href}
                                        className="truncate hover:text-foreground"
                                    >
                                        {crumb.label}
                                    </Link>
                                ) : (
                                    <span className="truncate text-foreground">
                                        {crumb.label}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ol>
                </nav>
            ) : (
                <div className="flex-1" />
            )}

            <ThemeToggle />

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        className="gap-2 px-2"
                        aria-label={t('admin.topbar.user_menu')}
                    >
                        <Avatar className="size-7">
                            <AvatarFallback className="text-xs">
                                {initials}
                            </AvatarFallback>
                        </Avatar>
                        <span className="hidden text-sm font-medium sm:inline">
                            {user?.name}
                        </span>
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                    <DropdownMenuLabel className="flex flex-col gap-0.5">
                        <span className="truncate">{user?.name}</span>
                        <span className="truncate text-xs font-normal text-muted-foreground">
                            {user?.email}
                        </span>
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem onSelect={() => router.post('/logout')}>
                        <LogOutIcon className="size-4" />
                        {t('admin.topbar.logout')}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </header>
    );
}
