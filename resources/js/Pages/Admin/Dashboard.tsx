import { Head, usePage } from '@inertiajs/react';
import {
    CalendarCheckIcon,
    GaugeIcon,
    TrendingUpIcon,
    UsersIcon,
} from 'lucide-react';

import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/components/admin/PageHeader';
import StatCard from '@/components/admin/StatCard';
import { useTranslations } from '@/lib/i18n';

const STATS = [
    { key: 'today_bookings', icon: CalendarCheckIcon, value: '0' },
    { key: 'revenue', icon: TrendingUpIcon, value: '—' },
    { key: 'occupancy', icon: GaugeIcon, value: '—' },
    { key: 'customers', icon: UsersIcon, value: '0' },
] as const;

export default function AdminDashboard() {
    const t = useTranslations();
    const { auth } = usePage().props;

    return (
        <AdminLayout>
            <Head title={t('admin.dashboard.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={
                        auth.user
                            ? t('admin.dashboard.welcome', {
                                  name: auth.user.name,
                              })
                            : t('admin.dashboard.title')
                    }
                    description={t('admin.dashboard.subtitle')}
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {STATS.map((stat) => (
                        <StatCard
                            key={stat.key}
                            icon={stat.icon}
                            value={stat.value}
                            label={t(`admin.dashboard.stat.${stat.key}`)}
                            hint={t(`admin.dashboard.stat.${stat.key}_hint`)}
                        />
                    ))}
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <section className="flex flex-col gap-2 rounded-2xl border border-border bg-card p-6 lg:col-span-2">
                        <h2 className="text-lg font-medium">
                            {t('admin.dashboard.placeholder_title')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('admin.dashboard.placeholder_body')}
                        </p>
                    </section>

                    <section className="flex flex-col gap-2 rounded-2xl border border-border bg-card p-6">
                        <h2 className="text-lg font-medium">
                            {t('admin.dashboard.today_title')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('admin.dashboard.today_empty')}
                        </p>
                    </section>

                    <section className="flex flex-col gap-2 rounded-2xl border border-border bg-card p-6 lg:col-span-3">
                        <h2 className="text-lg font-medium">
                            {t('admin.dashboard.recent_title')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('admin.dashboard.recent_empty')}
                        </p>
                    </section>
                </div>
            </div>
        </AdminLayout>
    );
}
