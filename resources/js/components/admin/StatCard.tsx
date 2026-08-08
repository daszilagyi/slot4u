import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

type StatCardProps = {
    label: string;
    value: string;
    hint?: string;
    icon: LucideIcon;
    className?: string;
    /** Makes the whole tile a link — for a number that has a list behind it. */
    href?: string;
};

/** Bento dashboard stat tile: label, big value, hint, accent icon. */
export default function StatCard({
    label,
    value,
    hint,
    icon: Icon,
    className,
    href,
}: StatCardProps) {
    // A tile with a destination is a link, not a div with a click handler: it keeps
    // the keyboard focus, the middle-click and the status-bar URL for free.
    const Wrapper = href === undefined ? 'div' : Link;

    return (
        <Wrapper
            {...(href === undefined ? {} : { href })}
            className={cn(
                'flex flex-col gap-3 rounded-2xl border border-border bg-card p-5 shadow-sm transition-colors hover:border-primary/40',
                className,
            )}
        >
            <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-muted-foreground">
                    {label}
                </span>
                <span className="flex size-9 items-center justify-center rounded-full bg-primary/10 text-primary">
                    <Icon className="size-5" />
                </span>
            </div>
            <div className="flex flex-col gap-0.5">
                <span className="text-3xl font-semibold tracking-tight">
                    {value}
                </span>
                {hint ? (
                    <span className="text-xs text-muted-foreground">
                        {hint}
                    </span>
                ) : null}
            </div>
        </Wrapper>
    );
}
