import type { PropsWithChildren, ReactNode } from 'react';

import AppLayout from '@/Layouts/AppLayout';

type AuthLayoutProps = PropsWithChildren<{
    title: string;
    subtitle: string;
    footer?: ReactNode;
}>;

/**
 * Centered auth card (login, password reset, email verification). Pulls the
 * shared centering from AppLayout and renders a titled card around the form.
 */
export default function AuthLayout({
    title,
    subtitle,
    footer,
    children,
}: AuthLayoutProps) {
    return (
        <AppLayout>
            <div className="w-full max-w-sm">
                <div className="rounded-xl border border-border bg-card p-8 shadow-sm">
                    <div className="mb-6 flex flex-col gap-1.5 text-center">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {title}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {subtitle}
                        </p>
                    </div>

                    {children}
                </div>

                {footer ? (
                    <div className="mt-6 text-center text-sm text-muted-foreground">
                        {footer}
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}
