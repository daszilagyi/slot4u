import { Head, router, useForm } from '@inertiajs/react';
import { CheckCircle2Icon, ClockIcon, CopyIcon, Trash2Icon } from 'lucide-react';
import { toast } from 'sonner';

import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/components/admin/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';

type Domain = {
    id: number;
    domain: string;
    is_primary: boolean;
    verified: boolean;
    verified_at: string | null;
    last_checked_at: string | null;
    last_error: string | null;
    txt_name: string;
    txt_value: string;
    provisioning_status: 'pending' | 'active' | 'failed' | null;
    certificate_status: string | null;
    provisioning_error: string | null;
    live: boolean;
};

type IndexProps = {
    domains: Domain[];
    cname_target: string;
    subdomain: string;
    public_host: string;
    provisioning_enabled: boolean;
};

export default function DomainsIndex({
    domains,
    cname_target,
    subdomain,
    public_host,
    provisioning_enabled,
}: IndexProps) {
    const t = useTranslations();

    return (
        <AdminLayout breadcrumbs={[{ label: t('admin.domains.title') }]}>
            <Head title={t('admin.domains.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('admin.domains.title')}
                    description={t('admin.domains.subtitle')}
                />

                <div className="rounded-xl border bg-card p-4 text-sm">
                    <p className="text-muted-foreground">
                        {t('admin.domains.current_host')}
                    </p>
                    <p className="mt-1 font-mono font-medium">{public_host}</p>
                    <p className="mt-2 text-xs text-muted-foreground">
                        {t('admin.domains.subdomain_label')}:{' '}
                        <span className="font-mono">{subdomain}</span>
                    </p>
                </div>

                <AddDomainForm />

                <div className="flex flex-col gap-3">
                    {domains.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('admin.domains.empty')}
                        </p>
                    ) : (
                        domains.map((domain) => (
                            <DomainCard
                                key={domain.id}
                                domain={domain}
                                cnameTarget={cname_target}
                                provisioningEnabled={provisioning_enabled}
                            />
                        ))
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}

function AddDomainForm() {
    const t = useTranslations();
    const form = useForm({ domain: '' });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.post('/settings/domains', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('domain');
                toast.success(t('admin.domains.flash.added'));
            },
        });
    }

    return (
        <form
            onSubmit={submit}
            className="flex flex-col gap-2 rounded-xl border bg-card p-4"
        >
            <Label htmlFor="domain">{t('admin.domains.domain_label')}</Label>
            <div className="flex flex-col gap-2 sm:flex-row">
                <Input
                    id="domain"
                    value={form.data.domain}
                    onChange={(event) =>
                        form.setData('domain', event.target.value)
                    }
                    placeholder={t('admin.domains.add_placeholder')}
                    autoComplete="off"
                    spellCheck={false}
                    aria-describedby="domain-hint"
                />
                <Button type="submit" disabled={form.processing}>
                    {t('admin.domains.add')}
                </Button>
            </div>
            <p id="domain-hint" className="text-xs text-muted-foreground">
                {t('admin.domains.add_hint')}
            </p>
            {form.errors.domain ? (
                <p className="text-xs text-destructive">{form.errors.domain}</p>
            ) : null}
        </form>
    );
}

function DomainCard({
    domain,
    cnameTarget,
    provisioningEnabled,
}: {
    domain: Domain;
    cnameTarget: string;
    provisioningEnabled: boolean;
}) {
    const t = useTranslations();

    function verify() {
        router.post(
            `/settings/domains/${domain.id}/verify`,
            {},
            { preserveScroll: true },
        );
    }

    function makePrimary() {
        router.post(
            `/settings/domains/${domain.id}/primary`,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(t('admin.domains.flash.primary_set')),
            },
        );
    }

    function retryProvisioning() {
        router.post(
            `/settings/domains/${domain.id}/provision`,
            {},
            { preserveScroll: true },
        );
    }

    function remove() {
        if (!window.confirm(t('admin.domains.delete_confirm'))) {
            return;
        }
        router.delete(`/settings/domains/${domain.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success(t('admin.domains.flash.deleted')),
        });
    }

    return (
        <div className="flex flex-col gap-3 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-mono text-sm font-medium">
                    {domain.domain}
                </span>
                {domain.verified ? (
                    <Badge variant="secondary" className="gap-1">
                        <CheckCircle2Icon className="size-3" aria-hidden />
                        {t('admin.domains.status.verified')}
                    </Badge>
                ) : (
                    <Badge variant="outline" className="gap-1">
                        <ClockIcon className="size-3" aria-hidden />
                        {t('admin.domains.status.pending')}
                    </Badge>
                )}
                {domain.is_primary ? (
                    <Badge>{t('admin.domains.status.primary')}</Badge>
                ) : null}
                {domain.verified && provisioningEnabled ? (
                    <Badge
                        variant={
                            domain.live
                                ? 'secondary'
                                : domain.provisioning_status === 'failed'
                                  ? 'destructive'
                                  : 'outline'
                        }
                    >
                        {domain.live
                            ? t('admin.domains.status.live')
                            : domain.provisioning_status === 'failed'
                              ? t('admin.domains.status.cert_failed')
                              : t('admin.domains.status.cert_pending')}
                    </Badge>
                ) : null}
            </div>

            {domain.verified ? null : (
                <div className="flex flex-col gap-3 rounded-lg bg-muted/50 p-3 text-sm">
                    <p className="font-medium">
                        {t('admin.domains.setup_title')}
                    </p>
                    <div className="flex flex-col gap-1">
                        <p className="text-muted-foreground">
                            {t('admin.domains.step_txt')}
                        </p>
                        <CopyRow
                            label={t('admin.domains.txt_name_label')}
                            value={domain.txt_name}
                        />
                        <CopyRow
                            label={t('admin.domains.txt_value_label')}
                            value={domain.txt_value}
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <p className="text-muted-foreground">
                            {t('admin.domains.step_cname')}
                        </p>
                        <CopyRow label="CNAME" value={cnameTarget} />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t('admin.domains.dns_hint')}
                    </p>
                </div>
            )}

            {domain.last_error ? (
                <div className="flex flex-col gap-0.5">
                    <p className="text-sm text-destructive">
                        {t(`admin.domains.errors.${domain.last_error}`)}
                    </p>
                    {domain.last_checked_at ? (
                        <p className="text-xs text-muted-foreground">
                            {t('admin.domains.last_checked', {
                                time: new Date(
                                    domain.last_checked_at,
                                ).toLocaleString(),
                            })}
                        </p>
                    ) : null}
                </div>
            ) : null}

            {domain.verified && provisioningEnabled && !domain.live ? (
                <div className="flex flex-col gap-1 rounded-lg bg-muted/50 p-3 text-sm">
                    <p className="text-muted-foreground">
                        {domain.provisioning_status === 'failed'
                            ? t('admin.domains.cert_failed_hint')
                            : t('admin.domains.cert_pending_hint')}
                    </p>
                    {domain.provisioning_error ? (
                        <p className="text-xs text-destructive">
                            {domain.provisioning_error}
                        </p>
                    ) : null}
                </div>
            ) : null}

            {domain.verified && !provisioningEnabled ? (
                <p className="text-xs text-muted-foreground">
                    {t('admin.domains.provisioning_disabled_hint')}
                </p>
            ) : null}

            <div className="flex flex-wrap gap-2">
                {domain.verified ? null : (
                    <Button size="sm" onClick={verify}>
                        {t('admin.domains.verify')}
                    </Button>
                )}
                {domain.verified && provisioningEnabled && !domain.live ? (
                    <Button size="sm" variant="outline" onClick={retryProvisioning}>
                        {t('admin.domains.retry_provisioning')}
                    </Button>
                ) : null}
                {domain.verified && !domain.is_primary ? (
                    <Button size="sm" variant="outline" onClick={makePrimary}>
                        {t('admin.domains.make_primary')}
                    </Button>
                ) : null}
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={remove}
                    className="gap-1 text-destructive"
                >
                    <Trash2Icon className="size-3.5" aria-hidden />
                    {t('admin.domains.delete')}
                </Button>
            </div>

            {domain.is_primary ? (
                <p className="text-xs text-muted-foreground">
                    {t('admin.domains.primary_hint')}
                </p>
            ) : null}
        </div>
    );
}

function CopyRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-center gap-2">
            <span className="w-20 shrink-0 text-xs text-muted-foreground">
                {label}
            </span>
            <code className="min-w-0 flex-1 truncate rounded bg-background px-2 py-1 text-xs">
                {value}
            </code>
            <Button
                type="button"
                size="icon"
                variant="ghost"
                className="size-7 shrink-0"
                onClick={() => navigator.clipboard?.writeText(value)}
                aria-label={label}
            >
                <CopyIcon className="size-3.5" aria-hidden />
            </Button>
        </div>
    );
}
