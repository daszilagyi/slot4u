import { Head, router, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { toast } from 'sonner';

import AdminLayout from '@/Layouts/AdminLayout';
import ConfirmDialog from '@/components/admin/ConfirmDialog';
import PageHeader from '@/components/admin/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';

type PrivacyRequestRow = {
    id: number;
    type: 'export' | 'erasure';
    status: 'pending' | 'completed' | 'rejected';
    subject: {
        id: number;
        name: string | null;
        email: string | null;
        anonymized: boolean;
    };
    requested_at: string | null;
    resolved_at: string | null;
    resolved_by: string | null;
    resolution_note: string | null;
};

type IndexProps = {
    requests: PrivacyRequestRow[];
};

/**
 * The tenant's data-subject request queue (SLO-159).
 *
 * Only erasure rows carry actions — an export is already served by the time it
 * appears here, and it is listed purely so the register reads as one chronology
 * of everything that happened to a customer's data.
 */
export default function PrivacyIndex({ requests }: IndexProps) {
    const t = useTranslations();
    const [approving, setApproving] = useState<PrivacyRequestRow | null>(null);
    const [rejecting, setRejecting] = useState<PrivacyRequestRow | null>(null);

    const rejectForm = useForm({ reason: '' });
    const pendingCount = requests.filter(
        (request) => request.status === 'pending',
    ).length;

    function approve(request: PrivacyRequestRow) {
        router.post(
            `/settings/privacy/${request.id}/approve`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success(t('admin.privacy.approved')),
            },
        );
    }

    function submitReject(event: FormEvent) {
        event.preventDefault();

        if (!rejecting) {
            return;
        }

        rejectForm.post(`/settings/privacy/${rejecting.id}/reject`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(t('admin.privacy.rejected'));
                setRejecting(null);
                rejectForm.reset();
            },
        });
    }

    return (
        <AdminLayout breadcrumbs={[{ label: t('admin.privacy.title') }]}>
            <Head title={t('admin.privacy.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('admin.privacy.title')}
                    description={t('admin.privacy.subtitle')}
                />

                {pendingCount > 0 ? (
                    <p className="text-sm font-medium text-amber-400">
                        {t('admin.privacy.pending_count', {
                            count: pendingCount,
                        })}
                    </p>
                ) : null}

                {requests.length === 0 ? (
                    <p className="rounded-xl border bg-card p-6 text-sm text-muted-foreground">
                        {t('admin.privacy.empty')}
                    </p>
                ) : (
                    <ul className="flex flex-col gap-3">
                        {requests.map((request) => (
                            <li
                                key={request.id}
                                className="flex flex-col gap-3 rounded-xl border bg-card p-4 sm:flex-row sm:items-start sm:justify-between"
                            >
                                <div className="flex flex-col gap-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {request.subject.name}
                                        </span>
                                        {request.subject.anonymized ? (
                                            <Badge variant="secondary">
                                                {t(
                                                    'admin.privacy.anonymized_badge',
                                                )}
                                            </Badge>
                                        ) : null}
                                    </div>
                                    <span className="text-sm text-muted-foreground">
                                        {request.subject.email}
                                    </span>
                                    <span className="text-sm">
                                        {t(
                                            `tenant.my.privacy.type.${request.type}`,
                                        )}
                                        {' · '}
                                        {t(
                                            `tenant.my.privacy.status.${request.status}`,
                                        )}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {t('admin.privacy.col_requested')}
                                        {': '}
                                        {formatDateTime(request.requested_at)}
                                        {request.resolved_at
                                            ? ` · ${t('admin.privacy.col_resolved')}: ${formatDateTime(request.resolved_at)}`
                                            : ''}
                                    </span>
                                    {request.resolved_by ? (
                                        <span className="text-xs text-muted-foreground">
                                            {t('admin.privacy.resolved_by', {
                                                name: request.resolved_by,
                                            })}
                                        </span>
                                    ) : null}
                                    {request.resolution_note ? (
                                        <p className="mt-1 text-sm">
                                            {request.resolution_note}
                                        </p>
                                    ) : null}
                                </div>

                                {request.status === 'pending' &&
                                request.type === 'erasure' ? (
                                    <div className="flex shrink-0 gap-2">
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                setRejecting(request)
                                            }
                                        >
                                            {t('admin.privacy.reject')}
                                        </Button>
                                        <Button
                                            variant="destructive"
                                            onClick={() =>
                                                setApproving(request)
                                            }
                                        >
                                            {t('admin.privacy.approve')}
                                        </Button>
                                    </div>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <ConfirmDialog
                open={approving !== null}
                onOpenChange={(open) => !open && setApproving(null)}
                title={t('admin.privacy.approve_confirm_title')}
                description={t('admin.privacy.approve_confirm_body')}
                confirmLabel={t('admin.privacy.approve_confirm_submit')}
                cancelLabel={t('admin.privacy.dismiss')}
                destructive
                onConfirm={() => {
                    if (approving) {
                        approve(approving);
                    }
                }}
            />

            <Dialog
                open={rejecting !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setRejecting(null);
                        // One dialog serves every row, so the previous request's
                        // reason and error must not greet the next one.
                        rejectForm.reset();
                        rejectForm.clearErrors();
                    }
                }}
            >
                <DialogContent className="sm:max-w-md">
                    <form onSubmit={submitReject} className="flex flex-col gap-4">
                        <DialogHeader>
                            <DialogTitle>
                                {t('admin.privacy.reject_title')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('admin.privacy.reject_body')}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="reason">
                                {t('admin.privacy.reject_label')}
                            </Label>
                            <textarea
                                id="reason"
                                rows={4}
                                className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                placeholder={t(
                                    'admin.privacy.reject_placeholder',
                                )}
                                value={rejectForm.data.reason}
                                onChange={(event) =>
                                    rejectForm.setData(
                                        'reason',
                                        event.target.value,
                                    )
                                }
                            />
                            {rejectForm.errors.reason ? (
                                <p className="text-sm text-red-500">
                                    {rejectForm.errors.reason}
                                </p>
                            ) : null}
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setRejecting(null)}
                            >
                                {t('admin.privacy.dismiss')}
                            </Button>
                            <Button
                                type="submit"
                                disabled={rejectForm.processing}
                            >
                                {t('admin.privacy.reject_submit')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
