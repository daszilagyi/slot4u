import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { toast } from 'sonner';

import FormSheet from '@/components/admin/FormSheet';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';

/** The little a decision form needs to know about the booking it acts on. */
export type ApprovalTarget = {
    id: number;
    code: string;
    customer: string | null;
};

type ResourceOption = { id: number; name: string };

/**
 * The two approval decisions that need a form (docs/04 §5, SLO-144), shared by the
 * booking list and the calendar card so both surfaces send the same payload to the
 * same endpoint. The one-click decisions (approve) stay in the quick-action menu —
 * see `lib/bookingActions`.
 *
 * Both post to the endpoints SLO-26 already shipped; permission, feature flag,
 * ownership scope and the state machine are all re-checked there.
 */
export function RejectBookingSheet({
    booking,
    onClose,
}: {
    booking: ApprovalTarget | null;
    onClose: () => void;
}) {
    const t = useTranslations();
    const form = useForm({ reason: '' });
    const { setData, reset, clearErrors } = form;

    // One sheet serves every row, so it has to forget the last one on the way out —
    // otherwise the next booking opens with a stale reason (or a stale error).
    function close() {
        reset();
        clearErrors();
        onClose();
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        if (booking === null) {
            return;
        }

        form.post(`/bookings/${booking.id}/reject`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(t('admin.approvals.rejected'));
                close();
            },
        });
    }

    return (
        <FormSheet
            open={booking !== null}
            onOpenChange={(open) => !open && close()}
            title={t('admin.approvals.reject.title')}
            description={t('admin.approvals.reject.description')}
            submitLabel={t('admin.approvals.reject.submit')}
            cancelLabel={t('admin.common.cancel')}
            onSubmit={submit}
            submitting={form.processing}
        >
            <p className="text-sm text-muted-foreground">
                {t('admin.approvals.subject', {
                    customer: booking?.customer ?? '—',
                    code: booking?.code ?? '',
                })}
            </p>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="reject-reason">
                    {t('admin.approvals.reject.reason')}
                </Label>
                <textarea
                    id="reject-reason"
                    rows={4}
                    className="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                    value={form.data.reason}
                    onChange={(event) => setData('reason', event.target.value)}
                    autoFocus
                />
                {form.errors.reason ? (
                    <p className="text-sm text-destructive">
                        {form.errors.reason}
                    </p>
                ) : (
                    <p className="text-xs text-muted-foreground">
                        {t('admin.approvals.reject.reason_hint')}
                    </p>
                )}
            </div>
        </FormSheet>
    );
}

/**
 * Offer the customer a different time. The original request is rejected and a fresh
 * requested booking is created at the proposed slot — one transaction, so an
 * unavailable slot leaves the original request standing (docs/04 §5).
 *
 * The resource selects are optional on purpose: left empty, the proposal keeps the
 * booking's current staff and room, so the common case (same person, different hour)
 * is one field.
 */
export function ProposeBookingSheet({
    booking,
    staff,
    rooms,
    onClose,
}: {
    booking: ApprovalTarget | null;
    staff: ResourceOption[];
    rooms: ResourceOption[];
    onClose: () => void;
}) {
    const t = useTranslations();
    const form = useForm({
        starts_at: '',
        ends_at: '',
        staff_id: '',
        room_id: '',
        reason: '',
    });
    const { setData, reset, clearErrors } = form;

    function close() {
        reset();
        clearErrors();
        onClose();
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        if (booking === null) {
            return;
        }

        form.post(`/bookings/${booking.id}/propose`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(t('admin.approvals.proposed'));
                close();
            },
            onError: (errors) => {
                // The proposed slot was taken (or the mode has no slot to move):
                // the whole transaction rolled back and the original request is
                // still there, so this is the only place it needs saying.
                if (errors.booking) {
                    toast.error(errors.booking);
                }
            },
        });
    }

    return (
        <FormSheet
            open={booking !== null}
            onOpenChange={(open) => !open && close()}
            title={t('admin.approvals.propose.title')}
            description={t('admin.approvals.propose.description')}
            submitLabel={t('admin.approvals.propose.submit')}
            cancelLabel={t('admin.common.cancel')}
            onSubmit={submit}
            submitting={form.processing}
        >
            <p className="text-sm text-muted-foreground">
                {t('admin.approvals.subject', {
                    customer: booking?.customer ?? '—',
                    code: booking?.code ?? '',
                })}
            </p>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="propose-starts">
                    {t('admin.approvals.propose.starts_at')}
                </Label>
                <Input
                    id="propose-starts"
                    type="datetime-local"
                    value={form.data.starts_at}
                    onChange={(event) =>
                        setData('starts_at', event.target.value)
                    }
                    autoFocus
                />
                {form.errors.starts_at ? (
                    <p className="text-sm text-destructive">
                        {form.errors.starts_at}
                    </p>
                ) : null}
            </div>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="propose-ends">
                    {t('admin.approvals.propose.ends_at')}
                </Label>
                <Input
                    id="propose-ends"
                    type="datetime-local"
                    value={form.data.ends_at}
                    onChange={(event) => setData('ends_at', event.target.value)}
                />
                {form.errors.ends_at ? (
                    <p className="text-sm text-destructive">
                        {form.errors.ends_at}
                    </p>
                ) : null}
            </div>

            {staff.length > 0 ? (
                <ResourceSelect
                    id="propose-staff"
                    label={t('admin.bookings.field.staff')}
                    value={form.data.staff_id}
                    items={staff}
                    error={form.errors.staff_id}
                    onChange={(value) => setData('staff_id', value)}
                />
            ) : null}

            {rooms.length > 0 ? (
                <ResourceSelect
                    id="propose-room"
                    label={t('admin.bookings.field.room')}
                    value={form.data.room_id}
                    items={rooms}
                    error={form.errors.room_id}
                    onChange={(value) => setData('room_id', value)}
                />
            ) : null}

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="propose-reason">
                    {t('admin.approvals.propose.reason')}
                </Label>
                <textarea
                    id="propose-reason"
                    rows={3}
                    className="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                    value={form.data.reason}
                    onChange={(event) => setData('reason', event.target.value)}
                />
                {form.errors.reason ? (
                    <p className="text-sm text-destructive">
                        {form.errors.reason}
                    </p>
                ) : null}
            </div>
        </FormSheet>
    );
}

/** An optional resource picker whose empty value means "keep the current one". */
function ResourceSelect({
    id,
    label,
    value,
    items,
    error,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    items: ResourceOption[];
    error?: string;
    onChange: (value: string) => void;
}) {
    const t = useTranslations();

    return (
        <div className="flex flex-col gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <select
                id={id}
                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                <option value="">
                    {t('admin.approvals.propose.resource_keep')}
                </option>
                {items.map((item) => (
                    <option key={item.id} value={item.id}>
                        {item.name}
                    </option>
                ))}
            </select>
            {error ? <p className="text-sm text-destructive">{error}</p> : null}
        </div>
    );
}
