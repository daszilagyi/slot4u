import type { PropsWithChildren } from 'react';

import { TooltipProvider } from '@/components/ui/tooltip';
import { ThemeProvider } from '@/lib/theme';

/**
 * Root providers shared by the client (app.tsx) and SSR (ssr.tsx) entry points.
 * The Sonner <Toaster /> is intentionally NOT here — it is mounted client-side
 * only (app.tsx), since toasts are a post-interaction concern.
 */
export default function AppProviders({ children }: PropsWithChildren) {
    return (
        <ThemeProvider>
            <TooltipProvider delayDuration={200}>{children}</TooltipProvider>
        </ThemeProvider>
    );
}
