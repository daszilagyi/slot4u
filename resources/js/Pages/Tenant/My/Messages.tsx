import { Head, usePage } from '@inertiajs/react';

import PublicLayout from '@/Layouts/PublicLayout';
import MessageThread from '@/components/messages/MessageThread';
import { useTranslations } from '@/lib/i18n';
import type { MessageBookingOption, ThreadMessage } from '@/types';

type MessagesProps = {
    messages: ThreadMessage[];
    bookings: MessageBookingOption[];
    selected_booking: number | null;
};

/**
 * Members area — the customer's conversation with the tenant (SLO-36). The
 * thread is always their own; opening the page marks the tenant's replies read.
 */
export default function Messages({
    messages,
    bookings,
    selected_booking,
}: MessagesProps) {
    const t = useTranslations();
    const { tenant } = usePage().props;

    return (
        <PublicLayout>
            <Head title={t('tenant.my_messages.title')} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-12 sm:px-6">
                <header className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('tenant.my_messages.title')}
                    </h1>
                    <p className="text-muted-foreground">
                        {t('tenant.my_messages.subtitle')}
                    </p>
                </header>

                <MessageThread
                    messages={messages}
                    bookings={bookings}
                    selectedBooking={selected_booking}
                    action="/my/messages"
                    viewer="customer"
                    otherPartyName={tenant?.name ?? ''}
                    emptyLabel={t('tenant.my_messages.empty')}
                />
            </div>
        </PublicLayout>
    );
}
