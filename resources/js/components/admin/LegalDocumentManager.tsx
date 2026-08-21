import { router, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

import ConfirmDialog from '@/components/admin/ConfirmDialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';

export type LegalDocumentRow = {
    id: number;
    type: 'terms' | 'privacy';
    version: string;
    title: string;
    href: string;
    effectiveFrom: string;
    state: 'in_force' | 'scheduled' | 'superseded';
    consents: number;
    deletable?: boolean;
};

type ManagerProps = {
    documents: LegalDocumentRow[];
    /** Where the publish form posts, and the base for a withdrawal. */
    endpoint: string;
};

/**
 * The version list and the publish form, shared by the tenant panel and the
 * superadmin one (SLO-161).
 *
 * A list of versions rather than an editor, because a version in force is never
 * edited: consent is consent to a text, and rewriting the text under a recorded
 * acceptance turns the proof into a claim. New wording is a new row, which is
 * also what sends existing customers through re-acceptance.
 *
 * The two panels differ only in where they post and whether a version can be
 * withdrawn — the platform's documents have no delete path at all, because a
 * platform version nobody accepted yet is still one every tenant admin may be
 * looking at right now.
 */
export default function LegalDocumentManager({
    documents,
    endpoint,
}: ManagerProps) {
    const t = useTranslations();
    const [deleting, setDeleting] = useState<LegalDocumentRow | null>(null);

    const form = useForm({
        type: 'privacy',
        version: '',
        title: '',
        body: '',
        url: '',
        effective_from: new Date().toISOString().slice(0, 10),
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(endpoint, {
            preserveScroll: true,
            onSuccess: () => form.reset('version', 'title', 'body', 'url'),
        });
    }

    return (
        <div className="flex flex-col gap-6">

                {documents.length === 0 ? (
                    <p className="rounded-lg border border-border bg-card px-4 py-3 text-sm text-muted-foreground">
                        {t('legal.admin.none')}
                    </p>
                ) : (
                    <ul className="flex flex-col gap-px overflow-hidden rounded-xl border border-border bg-border">
                        {documents.map((document) => (
                            <li
                                key={document.id}
                                className="flex flex-wrap items-center justify-between gap-3 bg-card px-4 py-3 text-sm"
                            >
                                <div className="flex flex-col gap-0.5">
                                    <span className="font-medium">
                                        {document.title}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {t(`legal.type.${document.type}`)}
                                        {' · '}
                                        {t('legal.version', {
                                            version: document.version,
                                        })}
                                        {' · '}
                                        {t('legal.effective_from', {
                                            date: formatDateTime(
                                                document.effectiveFrom,
                                            ),
                                        })}
                                    </span>
                                </div>

                                <div className="flex items-center gap-3">
                                    <Badge
                                        variant={
                                            document.state === 'in_force'
                                                ? 'default'
                                                : 'secondary'
                                        }
                                    >
                                        {t(`legal.admin.${document.state}`)}
                                    </Badge>
                                    <span className="text-muted-foreground">
                                        {t('legal.admin.consents')}:{' '}
                                        {document.consents}
                                    </span>
                                    <a
                                        href={document.href}
                                        target="_blank"
                                        rel="noopener"
                                        className="underline underline-offset-2"
                                    >
                                        {t('legal.open')}
                                    </a>
                                    {document.deletable === true && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setDeleting(document)
                                            }
                                        >
                                            {t('legal.admin.delete')}
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                <form
                    onSubmit={submit}
                    className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5"
                >
                    <h2 className="text-sm font-semibold">
                        {t('legal.admin.new')}
                    </h2>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="type">
                                {t('legal.admin.type_label')}
                            </Label>
                            <select
                                id="type"
                                value={form.data.type}
                                onChange={(event) =>
                                    form.setData('type', event.target.value)
                                }
                                className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="privacy">
                                    {t('legal.type.privacy')}
                                </option>
                                <option value="terms">
                                    {t('legal.type.terms')}
                                </option>
                            </select>
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="version">
                                {t('legal.admin.version_label')}
                            </Label>
                            <Input
                                id="version"
                                value={form.data.version}
                                onChange={(event) =>
                                    form.setData('version', event.target.value)
                                }
                            />
                            {form.errors.version !== undefined && (
                                <p className="text-sm text-destructive">
                                    {form.errors.version}
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="title">
                            {t('legal.admin.title_label')}
                        </Label>
                        <Input
                            id="title"
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                        />
                        {form.errors.title !== undefined && (
                            <p className="text-sm text-destructive">
                                {form.errors.title}
                            </p>
                        )}
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="body">
                            {t('legal.admin.body_label')}
                        </Label>
                        <textarea
                            id="body"
                            rows={8}
                            value={form.data.body}
                            onChange={(event) =>
                                form.setData('body', event.target.value)
                            }
                            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        />
                        {form.errors.body !== undefined && (
                            <p className="text-sm text-destructive">
                                {form.errors.body}
                            </p>
                        )}
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="url">{t('legal.admin.url_label')}</Label>
                        <Input
                            id="url"
                            type="url"
                            value={form.data.url}
                            onChange={(event) =>
                                form.setData('url', event.target.value)
                            }
                        />
                        {form.errors.url !== undefined && (
                            <p className="text-sm text-destructive">
                                {form.errors.url}
                            </p>
                        )}
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="effective_from">
                            {t('legal.admin.effective_from_label')}
                        </Label>
                        <Input
                            id="effective_from"
                            type="date"
                            value={form.data.effective_from}
                            onChange={(event) =>
                                form.setData(
                                    'effective_from',
                                    event.target.value,
                                )
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            {t('legal.admin.effective_hint')}
                        </p>
                        {form.errors.effective_from !== undefined && (
                            <p className="text-sm text-destructive">
                                {form.errors.effective_from}
                            </p>
                        )}
                    </div>

                    <div>
                        <Button type="submit" disabled={form.processing}>
                            {t('legal.admin.new')}
                        </Button>
                    </div>
                </form>
            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={t('legal.admin.delete')}
                description={t('legal.admin.delete_confirm', {
                    title: deleting?.title ?? '',
                })}
                confirmLabel={t('legal.admin.delete')}
                cancelLabel={t('legal.admin.cancel')}
                destructive
                onConfirm={() => {
                    if (deleting !== null) {
                        router.delete(`${endpoint}/${deleting.id}`, {
                            preserveScroll: true,
                        });
                    }
                    setDeleting(null);
                }}
            />
        </div>
    );
}
