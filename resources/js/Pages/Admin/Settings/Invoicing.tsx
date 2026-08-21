import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/components/admin/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';

type Option = { value: string; label: string };
type Block = { id: number; name: string; type: string };
type BankAccount = { id: number; name: string; account_number: string };

type InvoicingProps = {
    settings: {
        provider: string | null;
        hasApiKey: boolean;
        sellerName: string | null;
        sellerTaxNumber: string | null;
        sellerAddress: string | null;
        vatKey: string;
        blockId: number | null;
        bankAccountId: number | null;
        complete: boolean;
    };
    providers: Option[];
    blocks: Block[];
    bankAccounts: BankAccount[];
    providerError: string | null;
};

/**
 * The tenant's invoicing configuration (SLO-167).
 *
 * The API key field is always empty on load and blank means "keep the stored
 * one": the server never sends a live provider credential to the browser, so
 * there is nothing to pre-fill it with.
 *
 * The document block and bank account are lists fetched from the tenant's own
 * provider account, not free-text ids — an admin should not have to go and look
 * up a number, and a wrong one would only surface on a real invoice.
 */
export default function Invoicing({
    settings,
    providers,
    blocks,
    bankAccounts,
    providerError,
}: InvoicingProps) {
    const t = useTranslations();

    const form = useForm({
        provider: settings.provider ?? '',
        api_key: '',
        seller_name: settings.sellerName ?? '',
        seller_tax_number: settings.sellerTaxNumber ?? '',
        seller_address: settings.sellerAddress ?? '',
        vat_key: settings.vatKey,
        block_id: settings.blockId === null ? '' : String(settings.blockId),
        bank_account_id:
            settings.bankAccountId === null ? '' : String(settings.bankAccountId),
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/settings/invoicing', {
            preserveScroll: true,
            onSuccess: () => form.reset('api_key'),
        });
    }

    return (
        <AdminLayout>
            <Head title={t('invoicing.settings.title')} />

            <div className="flex max-w-2xl flex-col gap-6">
                <PageHeader
                    title={t('invoicing.settings.title')}
                    description={t('invoicing.settings.description')}
                />

                {!settings.complete && (
                    <p className="rounded-lg border border-amber-500/40 bg-amber-500/5 px-4 py-3 text-sm text-amber-400">
                        {t('invoicing.settings.incomplete')}
                    </p>
                )}

                {providerError !== null && (
                    <p className="rounded-lg border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                        {t('invoicing.settings.provider_error', {
                            message: providerError,
                        })}
                    </p>
                )}

                <form
                    onSubmit={submit}
                    className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5"
                >
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="provider">
                            {t('invoicing.settings.provider_label')}
                        </Label>
                        <select
                            id="provider"
                            value={form.data.provider}
                            onChange={(event) =>
                                form.setData('provider', event.target.value)
                            }
                            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">
                                {t('invoicing.settings.provider_none')}
                            </option>
                            {providers.map((provider) => (
                                <option
                                    key={provider.value}
                                    value={provider.value}
                                >
                                    {provider.label}
                                </option>
                            ))}
                        </select>
                        {form.errors.provider !== undefined && (
                            <p className="text-sm text-destructive">
                                {form.errors.provider}
                            </p>
                        )}
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="api_key">
                            {t('invoicing.settings.api_key_label')}
                        </Label>
                        <Input
                            id="api_key"
                            type="password"
                            autoComplete="off"
                            placeholder={
                                settings.hasApiKey
                                    ? t('invoicing.settings.api_key_set')
                                    : t('invoicing.settings.api_key_unset')
                            }
                            value={form.data.api_key}
                            onChange={(event) =>
                                form.setData('api_key', event.target.value)
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            {t('invoicing.settings.api_key_hint')}
                        </p>
                        {form.errors.api_key !== undefined && (
                            <p className="text-sm text-destructive">
                                {form.errors.api_key}
                            </p>
                        )}
                    </div>

                    {form.data.provider !== '' && (
                        <>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="block_id">
                                    {t('invoicing.settings.block_label')}
                                </Label>
                                <select
                                    id="block_id"
                                    value={form.data.block_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'block_id',
                                            event.target.value,
                                        )
                                    }
                                    className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                    disabled={blocks.length === 0}
                                >
                                    <option value="">
                                        {blocks.length === 0
                                            ? t(
                                                  'invoicing.settings.block_unavailable',
                                              )
                                            : t('invoicing.settings.choose')}
                                    </option>
                                    {blocks.map((block) => (
                                        <option key={block.id} value={block.id}>
                                            {block.name}
                                        </option>
                                    ))}
                                </select>
                                {form.errors.block_id !== undefined && (
                                    <p className="text-sm text-destructive">
                                        {form.errors.block_id}
                                    </p>
                                )}
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="bank_account_id">
                                    {t('invoicing.settings.bank_label')}
                                </Label>
                                <select
                                    id="bank_account_id"
                                    value={form.data.bank_account_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'bank_account_id',
                                            event.target.value,
                                        )
                                    }
                                    className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option value="">
                                        {t('invoicing.settings.bank_none')}
                                    </option>
                                    {bankAccounts.map((account) => (
                                        <option
                                            key={account.id}
                                            value={account.id}
                                        >
                                            {account.name} ·{' '}
                                            {account.account_number}
                                        </option>
                                    ))}
                                </select>
                                <p className="text-xs text-muted-foreground">
                                    {t('invoicing.settings.bank_hint')}
                                </p>
                            </div>
                        </>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="seller_name">
                                {t('invoicing.settings.seller_name')}
                            </Label>
                            <Input
                                id="seller_name"
                                value={form.data.seller_name}
                                onChange={(event) =>
                                    form.setData(
                                        'seller_name',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="seller_tax_number">
                                {t('invoicing.settings.seller_tax_number')}
                            </Label>
                            <Input
                                id="seller_tax_number"
                                value={form.data.seller_tax_number}
                                onChange={(event) =>
                                    form.setData(
                                        'seller_tax_number',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="seller_address">
                            {t('invoicing.settings.seller_address')}
                        </Label>
                        <Input
                            id="seller_address"
                            value={form.data.seller_address}
                            onChange={(event) =>
                                form.setData(
                                    'seller_address',
                                    event.target.value,
                                )
                            }
                        />
                    </div>

                    <div className="flex flex-col gap-1.5 sm:max-w-40">
                        <Label htmlFor="vat_key">
                            {t('invoicing.settings.vat_key')}
                        </Label>
                        <Input
                            id="vat_key"
                            value={form.data.vat_key}
                            onChange={(event) =>
                                form.setData('vat_key', event.target.value)
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            {t('invoicing.settings.vat_hint')}
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={form.processing}>
                            {t('invoicing.settings.save')}
                        </Button>
                        {settings.complete && (
                            <Badge variant="secondary">
                                {t('invoicing.settings.ready')}
                            </Badge>
                        )}
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
