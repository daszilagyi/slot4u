import { useForm, usePage } from '@inertiajs/react';
import { CheckCheckIcon, SendIcon } from 'lucide-react';
import { type FormEvent, useEffect, useRef } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { MessageBookingOption, ThreadMessage } from '@/types';

type MessageThreadProps = {
    messages: ThreadMessage[];
    bookings: MessageBookingOption[];
    selectedBooking: number | null;
    /** Where the reply form posts. */
    action: string;
    /**
     * Which side is reading: that side's messages sit on the right. On the
     * staff side that is every colleague's reply too — the tenant speaks as one
     * — but only the reader's own are labelled "you".
     */
    viewer: 'customer' | 'staff';
    /** The name shown on the other side's messages when no sender is known. */
    otherPartyName: string;
    emptyLabel: string;
};

/**
 * One tenant ↔ customer conversation (SLO-36), shared by the admin panel and
 * the members area. The same component on both sides, so the two never show a
 * different conversation.
 */
export default function MessageThread({
    messages,
    bookings,
    selectedBooking,
    action,
    viewer,
    otherPartyName,
    emptyLabel,
}: MessageThreadProps) {
    const t = useTranslations();
    const me = usePage().props.auth.user?.id ?? null;
    const endRef = useRef<HTMLDivElement>(null);
    const preselected = bookings.some((b) => b.id === selectedBooking)
        ? String(selectedBooking)
        : '';

    const form = useForm({ body: '', booking_id: preselected });

    // Keep the newest message in view, like any chat.
    useEffect(() => {
        endRef.current?.scrollIntoView({ block: 'end' });
    }, [messages.length]);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            body: data.body,
            booking_id: data.booking_id === '' ? null : Number(data.booking_id),
        }));
        form.post(action, {
            preserveScroll: true,
            onSuccess: () => form.reset('body'),
        });
    }

    return (
        <div className="flex flex-col gap-4">
            <div
                className="flex max-h-[60vh] min-h-48 flex-col gap-3 overflow-y-auto rounded-xl border border-border bg-muted/30 p-4"
                aria-live="polite"
            >
                {messages.length === 0 ? (
                    <p className="m-auto max-w-sm text-center text-sm text-muted-foreground">
                        {emptyLabel}
                    </p>
                ) : (
                    messages.map((message) => {
                        const own =
                            viewer === 'customer'
                                ? message.from_customer
                                : !message.from_customer;

                        return (
                            <div
                                key={message.id}
                                className={cn(
                                    'flex max-w-[85%] flex-col gap-1',
                                    own
                                        ? 'items-end self-end'
                                        : 'items-start self-start',
                                )}
                            >
                                <span className="text-xs text-muted-foreground">
                                    {message.sender_id !== null &&
                                    message.sender_id === me
                                        ? t('messages.you')
                                        : (message.sender_name ??
                                          otherPartyName)}
                                    {message.created_local
                                        ? ` · ${message.created_local}`
                                        : ''}
                                </span>
                                <div
                                    className={cn(
                                        'rounded-2xl px-4 py-2.5 text-sm break-words whitespace-pre-wrap',
                                        own
                                            ? 'rounded-br-md bg-primary text-primary-foreground'
                                            : 'rounded-bl-md border border-border bg-card',
                                    )}
                                >
                                    {message.body}
                                </div>
                                <div className="flex items-center gap-2">
                                    {message.booking_code ? (
                                        <Badge variant="outline">
                                            {t('messages.booking_chip', {
                                                code: message.booking_code,
                                            })}
                                        </Badge>
                                    ) : null}
                                    {own && message.read ? (
                                        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                            <CheckCheckIcon className="size-3.5" />
                                            {t('messages.read')}
                                        </span>
                                    ) : null}
                                </div>
                            </div>
                        );
                    })
                )}
                <div ref={endRef} />
            </div>

            <form onSubmit={submit} className="flex flex-col gap-3">
                <Label htmlFor="message-body" className="sr-only">
                    {t('messages.body_label')}
                </Label>
                <textarea
                    id="message-body"
                    rows={3}
                    required
                    maxLength={5000}
                    value={form.data.body}
                    placeholder={t('messages.placeholder')}
                    onChange={(event) =>
                        form.setData('body', event.target.value)
                    }
                    aria-invalid={form.errors.body ? true : undefined}
                    className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                {form.errors.body ? (
                    <p className="text-sm text-destructive">
                        {form.errors.body}
                    </p>
                ) : null}

                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    {bookings.length > 0 ? (
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="message-booking">
                                {t('messages.booking_label')}
                            </Label>
                            <select
                                id="message-booking"
                                value={form.data.booking_id}
                                onChange={(event) =>
                                    form.setData(
                                        'booking_id',
                                        event.target.value,
                                    )
                                }
                                className="h-9 max-w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            >
                                <option value="">
                                    {t('messages.booking_none')}
                                </option>
                                {bookings.map((booking) => (
                                    <option key={booking.id} value={booking.id}>
                                        {booking.label}
                                    </option>
                                ))}
                            </select>
                            {form.errors.booking_id ? (
                                <p className="text-sm text-destructive">
                                    {form.errors.booking_id}
                                </p>
                            ) : null}
                        </div>
                    ) : (
                        <span />
                    )}

                    <Button
                        type="submit"
                        disabled={
                            form.processing || form.data.body.trim() === ''
                        }
                    >
                        <SendIcon className="size-4" />
                        {t('messages.send')}
                    </Button>
                </div>
                <p className="text-xs text-muted-foreground">
                    {t('messages.no_email_hint')}
                </p>
            </form>
        </div>
    );
}
