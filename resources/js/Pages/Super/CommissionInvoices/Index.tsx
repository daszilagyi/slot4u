import { Head, Link, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { toast } from 'sonner';

import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    billingStatusBadgeClass,
    formatDate,
    formatDateTime,
    formatMoney,
} from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import type { Paginator } from '@/types';

type Dunning = {
    days_overdue: number;
    days_until_suspension: number;
    suspend_at: string;
};

type Invoice = {
    id: number;
    tenant: { id: number; name: string; slug: string } | null;
    period: string;
    commission_net_minor: number;
    vat_minor: number;
    total_gross_minor: number;
    currency: string;
    status: string;
    issued_at: string | null;
    due_at: string | null;
    paid_at: string | null;
    paid_method: string | null;
    can_manage: boolean;
    dunning: Dunning | null;
    /**
     * The external DOCUMENT's state (SLO-143) — not the invoice's status. The
     * debt can be perfectly normal while the paperwork failed, and only one of
     * those two is what dunning acts on.
     */
    document: {
        number: string | null;
        issued: boolean;
        stornoed: boolean;
        error: string | null;
        can_retry: boolean;
    };
};

type IndexProps = {
    invoices: Paginator<Invoice>;
    filters: { status: string | null; period: string | null; tenant_id: number | null };
    statuses: string[];
    grace_days: number;
    /** False while slot4u invoices through the sandbox — said out loud below. */
    invoicing_live: boolean;
};

type ActionType = 'paid' | 'void' | 'resend' | 'retry_document';

/**
 * Superadmin commission-invoice management (docs/10 §10, SLO-122). The
 * cross-tenant list of slot4u→tenant invoices with the dunning countdown, plus
 * manual settle / void / resend — each only offered while the invoice is still
 * outstanding (the backend enforces the same gate).
 */
export default function CommissionInvoicesIndex({
    invoices,
    filters,
    statuses,
    grace_days,
    invoicing_live,
}: IndexProps) {
    const t = useTranslations();

    // Filter form is uncontrolled-ish: seeded from the server filters, submitted
    // as a GET so the list stays shareable/back-safe via the query string.
    const [status, setStatus] = useState(filters.status ?? '');
    const [period, setPeriod] = useState(filters.period ?? '');
    const [tenantId, setTenantId] = useState(
        filters.tenant_id ? String(filters.tenant_id) : '',
    );

    function applyFilters(event: FormEvent) {
        event.preventDefault();
        router.get(
            '/commission-invoices',
            { status, period, tenant_id: tenantId },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function resetFilters() {
        setStatus('');
        setPeriod('');
        setTenantId('');
        router.get('/commission-invoices', {}, { preserveScroll: true, replace: true });
    }

    // The invoice + operation awaiting confirmation, plus the optional inputs.
    const [action, setAction] = useState<{ type: ActionType; invoice: Invoice } | null>(null);
    const [method, setMethod] = useState('');
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);

    function openAction(type: ActionType, invoice: Invoice) {
        setMethod('');
        setReason('');
        setAction({ type, invoice });
    }

    function submitAction() {
        if (!action) {
            return;
        }

        const { type, invoice } = action;
        const path =
            type === 'paid'
                ? 'mark-paid'
                : type === 'retry_document'
                  ? 'retry-document'
                  : type;
        const data = type === 'paid' ? { method } : type === 'void' ? { reason } : {};

        setProcessing(true);
        router.post(`/commission-invoices/${invoice.id}/${path}`, data, {
            preserveScroll: true,
            // The backend gates each action to an outstanding invoice; if it
            // lapsed under a concurrent action, surface the error rather than
            // closing the dialog silently.
            onError: (errors) => {
                if (errors.invoice) {
                    toast.error(errors.invoice);
                }
            },
            onFinish: () => {
                setProcessing(false);
                setAction(null);
            },
        });
    }

    return (
        <AppLayout platformAccent>
            <Head title={t('super.commission_invoices.title')} />

            <div className="w-full max-w-6xl">
                <div className="mb-6 flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('super.commission_invoices.title')}
                        </h1>
                        <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                            {t('super.commission_invoices.subtitle')}
                        </p>
                    </div>
                    <Link
                        href="/"
                        className="shrink-0 text-sm text-muted-foreground hover:text-foreground"
                    >
                        {t('super.commission_invoices.back')}
                    </Link>
                </div>

                {/* Filters */}
                <form onSubmit={applyFilters} className="mb-4 flex flex-wrap items-end gap-3">
                    <div className="flex flex-col gap-1">
                        <Label htmlFor="status">
                            {t('super.commission_invoices.filter.status')}
                        </Label>
                        <select
                            id="status"
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option value="">
                                {t('super.commission_invoices.filter.all_statuses')}
                            </option>
                            {statuses.map((value) => (
                                <option key={value} value={value}>
                                    {t(`super.commission_invoices.status.${value}`)}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex flex-col gap-1">
                        <Label htmlFor="period">
                            {t('super.commission_invoices.filter.period')}
                        </Label>
                        <Input
                            id="period"
                            value={period}
                            onChange={(e) => setPeriod(e.target.value)}
                            placeholder={t('super.commission_invoices.filter.period_placeholder')}
                            className="w-36"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <Label htmlFor="tenant_id">
                            {t('super.commission_invoices.filter.tenant_id')}
                        </Label>
                        <Input
                            id="tenant_id"
                            type="number"
                            inputMode="numeric"
                            value={tenantId}
                            onChange={(e) => setTenantId(e.target.value)}
                            className="w-28"
                        />
                    </div>
                    <Button type="submit">
                        {t('super.commission_invoices.filter.apply')}
                    </Button>
                    <Button type="button" variant="ghost" onClick={resetFilters}>
                        {t('super.commission_invoices.filter.reset')}
                    </Button>
                </form>

                <div className="overflow-x-auto rounded-xl border border-border">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">{t('super.commission_invoices.col.tenant')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.commission_invoices.col.period')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.commission_invoices.col.net')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.commission_invoices.col.vat')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.commission_invoices.col.gross')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.commission_invoices.col.status')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.commission_invoices.col.issued')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.commission_invoices.col.due')}</th>
                                <th className="px-4 py-3 font-medium">{t('super.commission_invoices.col.dunning')}</th>
                                <th className="px-4 py-3 text-right font-medium">{t('super.commission_invoices.col.actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoices.data.length === 0 ? (
                                <tr>
                                    <td colSpan={10} className="px-4 py-8 text-center text-muted-foreground">
                                        {t('super.commission_invoices.empty')}
                                    </td>
                                </tr>
                            ) : (
                                invoices.data.map((invoice) => (
                                    <tr key={invoice.id} className="border-t border-border align-top">
                                        <td className="px-4 py-3">
                                            {invoice.tenant?.name ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">{invoice.period}</td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {formatMoney(invoice.commission_net_minor, invoice.currency)}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums text-muted-foreground">
                                            {formatMoney(invoice.vat_minor, invoice.currency)}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {formatMoney(invoice.total_gross_minor, invoice.currency)}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${billingStatusBadgeClass(invoice.status)}`}>
                                                {t(`super.commission_invoices.status.${invoice.status}`)}
                                            </span>
                                            {invoice.status === 'paid' && invoice.paid_method ? (
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    {t('super.commission_invoices.paid_method', { method: invoice.paid_method })}
                                                </div>
                                            ) : null}
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                            {formatDate(invoice.issued_at)}
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                            {formatDate(invoice.due_at)}
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            {invoice.dunning ? (
                                                <div className="text-red-400">
                                                    <div>
                                                        {t('super.commission_invoices.dunning.overdue_days', {
                                                            days: invoice.dunning.days_overdue,
                                                        })}
                                                    </div>
                                                    <div className="text-muted-foreground">
                                                        {invoice.dunning.days_until_suspension > 0
                                                            ? t('super.commission_invoices.dunning.suspend_in', {
                                                                  days: invoice.dunning.days_until_suspension,
                                                              })
                                                            : t('super.commission_invoices.dunning.suspend_due')}
                                                    </div>
                                                </div>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    {t('super.commission_invoices.dunning.none')}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="mb-1 text-right text-xs">
                                                {invoice.document.issued ? (
                                                    <span className="text-muted-foreground">
                                                        {t('super.commission_invoices.document.issued', {
                                                            number: invoice.document.number ?? '—',
                                                        })}
                                                        {invoice.document.stornoed
                                                            ? ` · ${t('super.commission_invoices.document.stornoed')}`
                                                            : ''}
                                                    </span>
                                                ) : invoice.document.error ? (
                                                    <span className="text-destructive">
                                                        {t('super.commission_invoices.document.failed')}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        {t('super.commission_invoices.document.missing')}
                                                    </span>
                                                )}
                                            </div>
                                            {invoice.document.can_retry ? (
                                                <div className="mb-2 flex justify-end">
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => openAction('retry_document', invoice)}
                                                    >
                                                        {t('super.commission_invoices.action.retry_document')}
                                                    </Button>
                                                </div>
                                            ) : null}
                                            {invoice.can_manage ? (
                                                <div className="flex justify-end gap-2">
                                                    <Button size="sm" variant="outline" onClick={() => openAction('paid', invoice)}>
                                                        {t('super.commission_invoices.action.mark_paid')}
                                                    </Button>
                                                    <Button size="sm" variant="outline" onClick={() => openAction('resend', invoice)}>
                                                        {t('super.commission_invoices.action.resend')}
                                                    </Button>
                                                    <Button size="sm" variant="destructive" onClick={() => openAction('void', invoice)}>
                                                        {t('super.commission_invoices.action.void')}
                                                    </Button>
                                                </div>
                                            ) : invoice.paid_at ? (
                                                <div className="text-right text-xs text-muted-foreground">
                                                    {t('super.commission_invoices.paid_at', {
                                                        time: formatDateTime(invoice.paid_at),
                                                    })}
                                                </div>
                                            ) : null}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <p className="mt-3 text-xs text-muted-foreground">
                    {t('super.commission_invoices.grace_note', { days: grace_days })}
                </p>

                {/* Said out loud rather than assumed (SLO-143): under the sandbox
                    the documents are real files with no legal standing, and a
                    screen that showed them without saying so would be the most
                    expensive kind of quiet. */}
                {!invoicing_live ? (
                    <p className="mt-3 rounded-lg border border-amber-500/40 bg-amber-500/5 px-4 py-3 text-sm text-amber-400">
                        {t('super.commission_invoices.document.sandbox_note')}
                    </p>
                ) : null}

                {invoices.last_page > 1 ? (
                    <div className="mt-4 flex flex-wrap gap-1">
                        {invoices.links.map((link, i) => (
                            <button
                                key={i}
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })}
                                className={`rounded-md px-3 py-1.5 text-sm ${
                                    link.active
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-accent disabled:opacity-40'
                                }`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>

            <Dialog open={action !== null} onOpenChange={(open) => !open && setAction(null)}>
                <DialogContent className="sm:max-w-md">
                    {action ? (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    {t(dialogTitleKey(action.type))}
                                </DialogTitle>
                                <DialogDescription>
                                    {action.type === 'resend'
                                        ? t('super.commission_invoices.resend_confirm')
                                        : action.type === 'retry_document'
                                          ? t('super.commission_invoices.retry_document_confirm')
                                          : t(`super.commission_invoices.${dialogKey(action.type)}.description`, {
                                              tenant: action.invoice.tenant?.name ?? '—',
                                              period: action.invoice.period,
                                          })}
                                </DialogDescription>
                            </DialogHeader>

                            {action.type === 'paid' ? (
                                <div className="flex flex-col gap-2">
                                    <Label htmlFor="method">
                                        {t('super.commission_invoices.mark_paid_dialog.method')}
                                    </Label>
                                    <Input
                                        id="method"
                                        value={method}
                                        onChange={(e) => setMethod(e.target.value)}
                                        placeholder={t('super.commission_invoices.mark_paid_dialog.method_placeholder')}
                                    />
                                </div>
                            ) : null}

                            {action.type === 'void' ? (
                                <div className="flex flex-col gap-2">
                                    <Label htmlFor="reason">
                                        {t('super.commission_invoices.void_dialog.reason')}
                                    </Label>
                                    <textarea
                                        id="reason"
                                        value={reason}
                                        onChange={(e) => setReason(e.target.value)}
                                        placeholder={t('super.commission_invoices.void_dialog.reason_placeholder')}
                                        rows={3}
                                        className="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                                    />
                                </div>
                            ) : null}

                            <DialogFooter>
                                <Button variant="outline" onClick={() => setAction(null)}>
                                    {t('super.commission_invoices.cancel')}
                                </Button>
                                <Button
                                    variant={action.type === 'void' ? 'destructive' : 'default'}
                                    disabled={processing}
                                    onClick={submitAction}
                                >
                                    {t(`super.commission_invoices.${confirmKey(action.type)}`)}
                                </Button>
                            </DialogFooter>
                        </>
                    ) : null}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function dialogKey(type: ActionType): string {
    return type === 'paid' ? 'mark_paid_dialog' : 'void_dialog';
}

function dialogTitleKey(type: ActionType): string {
    if (type === 'paid') {
        return 'super.commission_invoices.mark_paid_dialog.title';
    }
    if (type === 'void') {
        return 'super.commission_invoices.void_dialog.title';
    }
    if (type === 'retry_document') {
        return 'super.commission_invoices.action.retry_document';
    }

    return 'super.commission_invoices.resend_dialog.title';
}

function confirmKey(type: ActionType): string {
    if (type === 'paid') {
        return 'mark_paid_dialog.confirm';
    }
    if (type === 'void') {
        return 'void_dialog.confirm';
    }
    if (type === 'retry_document') {
        return 'action.retry_document';
    }

    return 'action.resend';
}
