import { Head, Link } from '@inertiajs/react';
import { ArrowLeftIcon, ContactIcon } from 'lucide-react';

import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/components/admin/PageHeader';
import MessageThread from '@/components/messages/MessageThread';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';
import { usePermissions } from '@/lib/permissions';
import type { MessageBookingOption, ThreadMessage } from '@/types';

type ShowProps = {
    customer: { id: number; name: string; email: string };
    messages: ThreadMessage[];
    bookings: MessageBookingOption[];
    selected_booking: number | null;
};

/** One customer's thread in the admin panel (SLO-36). */
export default function MessagesShow({
    customer,
    messages,
    bookings,
    selected_booking,
}: ShowProps) {
    const t = useTranslations();
    const can = usePermissions();

    return (
        <AdminLayout
            breadcrumbs={[
                { label: t('admin.messages.title'), href: '/messages' },
                { label: customer.name },
            ]}
        >
            <Head title={`${t('admin.messages.title')} · ${customer.name}`} />

            <div className="flex max-w-3xl flex-col gap-6">
                <Link
                    href="/messages"
                    className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeftIcon className="size-4" />
                    {t('admin.messages.back')}
                </Link>

                <PageHeader
                    title={customer.name}
                    description={customer.email}
                    actions={
                        can('customer.view') ? (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/customers/${customer.id}`}>
                                    <ContactIcon className="size-4" />
                                    {t('admin.messages.open_customer')}
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                <MessageThread
                    messages={messages}
                    bookings={bookings}
                    selectedBooking={selected_booking}
                    action={`/messages/${customer.id}`}
                    viewer="staff"
                    otherPartyName={customer.name}
                    emptyLabel={t('admin.messages.thread_empty')}
                />
            </div>
        </AdminLayout>
    );
}
