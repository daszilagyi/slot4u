import type { PropsWithChildren } from 'react';

export default function AppLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <main className="flex flex-1 items-center justify-center px-6 py-16">
                {children}
            </main>
        </div>
    );
}
