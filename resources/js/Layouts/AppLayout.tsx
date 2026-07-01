import type { PropsWithChildren } from 'react';

import ImpersonationBanner from '@/components/ImpersonationBanner';

export default function AppLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <ImpersonationBanner />
            <main className="flex flex-1 items-center justify-center px-6 py-16">
                {children}
            </main>
        </div>
    );
}
