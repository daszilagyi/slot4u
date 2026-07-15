import { Head, useForm } from '@inertiajs/react';
import { ChevronDownIcon, RotateCcwIcon } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';

import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/components/admin/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';

type TemplateOverride = {
    subject: string;
    body: string;
    enabled: boolean;
};

type Template = {
    key: string;
    default: { subject: string; body: string };
    override: TemplateOverride | null;
    variables: string[];
};

type IndexProps = {
    templates: Template[];
};

export default function TemplatesIndex({ templates }: IndexProps) {
    const t = useTranslations();

    return (
        <AdminLayout breadcrumbs={[{ label: t('admin.templates.title') }]}>
            <Head title={t('admin.templates.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('admin.templates.title')}
                    description={t('admin.templates.subtitle')}
                />

                <p className="max-w-3xl text-sm text-muted-foreground">
                    {t('admin.templates.greeting_note')}
                </p>

                <div className="flex flex-col gap-3">
                    {templates.map((template) => (
                        <TemplateCard key={template.key} template={template} />
                    ))}
                </div>
            </div>
        </AdminLayout>
    );
}

function TemplateCard({ template }: { template: Template }) {
    const t = useTranslations();
    const [open, setOpen] = useState(false);
    const bodyRef = useRef<HTMLTextAreaElement>(null);
    const isCustom = template.override?.enabled ?? false;

    const form = useForm({
        subject: template.override?.subject ?? template.default.subject,
        body: template.override?.body ?? template.default.body,
        enabled: template.override?.enabled ?? false,
    });

    function submit() {
        form.put(`/settings/templates/${template.key}`, {
            preserveScroll: true,
            onSuccess: () => toast.success(t('admin.templates.saved')),
        });
    }

    function reset() {
        // Use form.delete (not the bare router) so form.processing guards both
        // buttons during the request, and re-seed the fields to the platform
        // default on success — the card does not remount, so its inputs would
        // otherwise keep showing the just-deleted custom text.
        form.delete(`/settings/templates/${template.key}`, {
            preserveScroll: true,
            onSuccess: () => {
                form.setData({
                    subject: template.default.subject,
                    body: template.default.body,
                    enabled: false,
                });
                toast.success(t('admin.templates.reset_done'));
            },
        });
    }

    function insertVariable(name: string) {
        const token = `:${name}`;
        const textarea = bodyRef.current;
        if (textarea === null) {
            form.setData('body', `${form.data.body}${token}`);
            return;
        }
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const next =
            form.data.body.slice(0, start) + token + form.data.body.slice(end);
        form.setData('body', next);
        // Restore focus with the caret after the inserted token.
        requestAnimationFrame(() => {
            textarea.focus();
            const caret = start + token.length;
            textarea.setSelectionRange(caret, caret);
        });
    }

    return (
        <section className="rounded-2xl border border-border bg-card">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                aria-controls={`template-panel-${template.key}`}
                className="flex w-full items-center justify-between gap-3 p-5 text-left"
            >
                <span className="flex items-center gap-3">
                    <span className="font-medium">
                        {t(`admin.templates.keys.${template.key}`)}
                    </span>
                    <Badge variant={isCustom ? 'default' : 'secondary'}>
                        {isCustom
                            ? t('admin.templates.custom_badge')
                            : t('admin.templates.default_badge')}
                    </Badge>
                </span>
                <ChevronDownIcon
                    className={`size-5 shrink-0 text-muted-foreground transition-transform ${
                        open ? 'rotate-180' : ''
                    }`}
                />
            </button>

            {open ? (
                <div
                    id={`template-panel-${template.key}`}
                    className="flex flex-col gap-4 border-t border-border p-5"
                >
                    <label className="flex items-center gap-2 text-sm font-medium">
                        <input
                            type="checkbox"
                            checked={form.data.enabled}
                            onChange={(e) =>
                                form.setData('enabled', e.target.checked)
                            }
                            className="size-4 rounded border-input"
                        />
                        {t('admin.templates.enabled_label')}
                    </label>

                    <div className="flex flex-col gap-2">
                        <Label htmlFor={`subject-${template.key}`}>
                            {t('admin.templates.subject_label')}
                        </Label>
                        <Input
                            id={`subject-${template.key}`}
                            value={form.data.subject}
                            onChange={(e) =>
                                form.setData('subject', e.target.value)
                            }
                        />
                        {form.errors.subject ? (
                            <p className="text-sm text-destructive">
                                {form.errors.subject}
                            </p>
                        ) : null}
                    </div>

                    <div className="flex flex-col gap-2">
                        <Label htmlFor={`body-${template.key}`}>
                            {t('admin.templates.body_label')}
                        </Label>
                        <textarea
                            id={`body-${template.key}`}
                            ref={bodyRef}
                            value={form.data.body}
                            onChange={(e) => form.setData('body', e.target.value)}
                            rows={8}
                            className="rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                        {form.errors.body ? (
                            <p className="text-sm text-destructive">
                                {form.errors.body}
                            </p>
                        ) : null}
                    </div>

                    <div className="flex flex-col gap-2">
                        <p className="text-sm font-medium">
                            {t('admin.templates.variables_title')}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {t('admin.templates.variables_hint')}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {template.variables.map((name) => (
                                <button
                                    key={name}
                                    type="button"
                                    onClick={() => insertVariable(name)}
                                    className="rounded-md border border-input bg-muted/40 px-2 py-1 font-mono text-xs hover:bg-accent"
                                >
                                    :{name}
                                </button>
                            ))}
                        </div>
                    </div>

                    <details className="rounded-md border border-dashed border-border bg-muted/20 p-3">
                        <summary className="cursor-pointer text-sm font-medium">
                            {t('admin.templates.default_preview_title')}
                        </summary>
                        <p className="mt-2 text-sm font-medium">
                            {template.default.subject}
                        </p>
                        <pre className="mt-1 whitespace-pre-wrap font-sans text-sm text-muted-foreground">
                            {template.default.body}
                        </pre>
                    </details>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={form.processing}
                        >
                            {t('admin.templates.save')}
                        </Button>
                        {template.override !== null ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={reset}
                                disabled={form.processing}
                            >
                                <RotateCcwIcon className="size-4" />
                                {t('admin.templates.reset')}
                            </Button>
                        ) : null}
                    </div>
                </div>
            ) : null}
        </section>
    );
}
