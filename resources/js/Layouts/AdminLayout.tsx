import { motion } from 'framer-motion';
import { useState, type ReactNode } from 'react';

import ImpersonationBanner from '@/components/ImpersonationBanner';
import AdminTopbar, { type Breadcrumb } from '@/components/admin/AdminTopbar';
import Sidebar from '@/components/admin/Sidebar';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { useTranslations } from '@/lib/i18n';

type AdminLayoutProps = {
    children: ReactNode;
    breadcrumbs?: Breadcrumb[];
};

/**
 * Tenant admin shell (SLO-15): fixed sidebar on desktop, slide-over drawer on
 * mobile, topbar, and an animated content region. Every tenant admin CRUD page
 * renders inside this layout.
 */
export default function AdminLayout({
    children,
    breadcrumbs,
}: AdminLayoutProps) {
    const t = useTranslations();
    const [menuOpen, setMenuOpen] = useState(false);

    return (
        <div className="min-h-screen bg-background text-foreground">
            <ImpersonationBanner />

            <a
                href="#admin-main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-sm focus:text-primary-foreground"
            >
                {t('admin.topbar.skip_to_content')}
            </a>

            <div className="flex">
                <aside className="hidden w-64 shrink-0 border-r border-border lg:block">
                    <div className="sticky top-0 h-screen overflow-y-auto">
                        <Sidebar />
                    </div>
                </aside>

                <Sheet open={menuOpen} onOpenChange={setMenuOpen}>
                    <SheetContent side="left" className="w-72 p-0">
                        <SheetTitle className="sr-only">
                            {t('admin.nav.label')}
                        </SheetTitle>
                        <Sidebar onNavigate={() => setMenuOpen(false)} />
                    </SheetContent>
                </Sheet>

                <div className="flex min-w-0 flex-1 flex-col">
                    <AdminTopbar
                        breadcrumbs={breadcrumbs}
                        onOpenMenu={() => setMenuOpen(true)}
                    />
                    <motion.main
                        id="admin-main"
                        initial={{ opacity: 0, y: 8 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.2, ease: 'easeOut' }}
                        className="flex-1 px-4 py-6 sm:px-6 lg:px-8"
                    >
                        <div className="mx-auto w-full max-w-6xl">
                            {children}
                        </div>
                    </motion.main>
                </div>
            </div>
        </div>
    );
}
