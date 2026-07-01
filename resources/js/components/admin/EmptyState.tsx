import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

type EmptyStateProps = {
    icon?: LucideIcon;
    title: string;
    description?: string;
    action?: ReactNode;
};

/** Friendly empty placeholder for tables and lists (SLO-15 CRUD building block). */
export default function EmptyState({
    icon: Icon,
    title,
    description,
    action,
}: EmptyStateProps) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-border px-6 py-14 text-center">
            {Icon ? (
                <div className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <Icon className="size-6" />
                </div>
            ) : null}
            <div className="flex flex-col gap-1">
                <h3 className="text-base font-medium">{title}</h3>
                {description ? (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                ) : null}
            </div>
            {action}
        </div>
    );
}
