import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

import PublicLayout from '@/Layouts/PublicLayout';
import ConfirmDialog from '@/components/admin/ConfirmDialog';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';

type PrivacyRequestRow = {
    id: number;
    type: 'export' | 'erasure';
    status: 'pending' | 'completed' | 'rejected';
    requested_at: string | null;
    resolved_at: string | null;
    resolution_note: string | null;
};

type PrivacyProps = {
    requests: PrivacyRequestRow[];
    has_pending_erasure: boolean;
    anonymized: boolean;
};

/**
 * Members area — the customer's own data-protection page (SLO-159).
 *
 * The two rights read differently on purpose. The export is a plain download
 * link: it is answered on the spot. The erasure is a request behind a confirm
 * dialog, and the copy says who decides — the tenant is the controller, and a
 * page that implied slot4u erases on demand would be promising something it is
 * not entitled to do.
 */
export default function Privacy({
    requests,
    has_pending_erasure: hasPendingErasure,
    anonymized,
}: PrivacyProps) {
    const t = useTranslations();
    const [confirming, setConfirming] = useState(false);
    const form = useForm({});

    function submitErasure() {
        form.post('/my/privacy/erasure', {
            preserveScroll: true,
            onSuccess: () => toast.success(t('tenant.my.privacy.erasure_submitted')),
        });
    }

    return (
        <PublicLayout>
            <Head title={t('tenant.my.privacy.title')} />

            <div className="mx-auto flex w-full max-w-2xl flex-col gap-8 px-4 py-12 sm:px-6">
                <header className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('tenant.my.privacy.title')}
                    </h1>
                    <p className="text-muted-foreground">
                        {t('tenant.my.privacy.subtitle')}
                    </p>
                </header>

                <section className="flex flex-col gap-4 rounded-xl border border-border bg-card p-6">
                    <h2 className="text-sm font-semibold tracking-tight text-muted-foreground uppercase">
                        {t('tenant.my.privacy.export_title')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {t('tenant.my.privacy.export_body')}
                    </p>
                    {/* A plain anchor, not an Inertia Link: the response is a
                        file download, which the Inertia visit protocol cannot
                        represent. */}
                    <Button asChild className="self-start">
                        <a href="/my/privacy/export" download>
                            {t('tenant.my.privacy.export_action')}
                        </a>
                    </Button>
                </section>

                <section className="flex flex-col gap-4 rounded-xl border border-border bg-card p-6">
                    <h2 className="text-sm font-semibold tracking-tight text-muted-foreground uppercase">
                        {t('tenant.my.privacy.erasure_title')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {t('tenant.my.privacy.erasure_body')}
                    </p>

                    {anonymized ? (
                        <p className="text-sm font-medium">
                            {t('tenant.my.privacy.anonymized')}
                        </p>
                    ) : hasPendingErasure ? (
                        <p className="text-sm font-medium">
                            {t('tenant.my.privacy.erasure_pending')}
                        </p>
                    ) : (
                        <Button
                            variant="destructive"
                            className="self-start"
                            disabled={form.processing}
                            onClick={() => setConfirming(true)}
                        >
                            {t('tenant.my.privacy.erasure_action')}
                        </Button>
                    )}
                </section>

                <section className="flex flex-col gap-4">
                    <h2 className="text-sm font-semibold tracking-tight text-muted-foreground uppercase">
                        {t('tenant.my.privacy.history_title')}
                    </h2>

                    {requests.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('tenant.my.privacy.history_empty')}
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-3">
                            {requests.map((request) => (
                                <li
                                    key={request.id}
                                    className="flex flex-col gap-1 rounded-xl border border-border bg-card p-4"
                                >
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <span className="font-medium">
                                            {t(
                                                `tenant.my.privacy.type.${request.type}`,
                                            )}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {t(
                                                `tenant.my.privacy.status.${request.status}`,
                                            )}
                                        </span>
                                    </div>
                                    <span className="text-xs text-muted-foreground">
                                        {formatDateTime(request.requested_at)}
                                    </span>
                                    {request.resolution_note ? (
                                        <p className="mt-2 text-sm">
                                            <span className="text-muted-foreground">
                                                {t(
                                                    'tenant.my.privacy.rejected_reason',
                                                )}
                                                {': '}
                                            </span>
                                            {request.resolution_note}
                                        </p>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title={t('tenant.my.privacy.erasure_confirm_title')}
                description={t('tenant.my.privacy.erasure_confirm_body')}
                confirmLabel={t('tenant.my.privacy.erasure_confirm_submit')}
                cancelLabel={t('tenant.my.privacy.erasure_confirm_dismiss')}
                destructive
                onConfirm={submitErasure}
            />
        </PublicLayout>
    );
}
