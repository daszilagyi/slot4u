import { Head, useForm } from '@inertiajs/react';
import { RotateCcwIcon } from 'lucide-react';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import AppLayout from '@/Layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { postFormJson } from '@/lib/http';
import { useTranslations } from '@/lib/i18n';

type Text = {
    subject: string;
    body: string;
    outro: string | null;
};

type Mail = {
    key: string;
    group: 'platform' | 'customer';
    has_outro: boolean;
    has_button: boolean;
    variables: string[];
    default: Text;
    stored: Text | null;
};

type IndexProps = {
    mails: Mail[];
};

type Field = 'subject' | 'body' | 'outro';

type Preview = { subject: string; html: string };

const textareaClass =
    'rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

/**
 * The words of every system email (SLO-246, docs/27 §5), one mail at a time.
 *
 * Like the design page, the preview is the server's real render of the draft —
 * with sample values and the current brand — not a React lookalike.
 */
export default function SuperMailTextsIndex({ mails }: IndexProps) {
    const t = useTranslations();
    const [selected, setSelected] = useState(mails[0]?.key ?? '');
    const [dirty, setDirty] = useState(false);

    const mail = mails.find((m) => m.key === selected) ?? mails[0];

    function select(key: string) {
        if (key === selected) return;
        if (dirty && !window.confirm(t('super.mail_texts.unsaved_confirm')))
            return;

        setDirty(false);
        setSelected(key);
    }

    return (
        <AppLayout>
            <Head title={t('super.mail_texts.title')} />

            <div className="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-8 sm:px-6">
                <header className="flex flex-col gap-2">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('super.mail_texts.title')}
                    </h1>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        {t('super.mail_texts.subtitle')}
                    </p>
                </header>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,15rem)_minmax(0,1fr)]">
                    <nav
                        className="flex flex-col gap-5"
                        aria-label={t('super.mail_texts.title')}
                    >
                        {(['platform', 'customer'] as const).map((group) => (
                            <div key={group} className="flex flex-col gap-1">
                                <p className="px-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    {t(`super.mail_texts.groups.${group}`)}
                                </p>
                                {mails
                                    .filter((m) => m.group === group)
                                    .map((m) => (
                                        <button
                                            key={m.key}
                                            type="button"
                                            onClick={() => select(m.key)}
                                            aria-current={
                                                m.key === mail?.key
                                                    ? 'page'
                                                    : undefined
                                            }
                                            className={`flex items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left text-sm ${
                                                m.key === mail?.key
                                                    ? 'bg-accent font-medium text-accent-foreground'
                                                    : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground'
                                            }`}
                                        >
                                            <span>
                                                {t(
                                                    `super.mail_texts.names.${m.key}`,
                                                )}
                                            </span>
                                            {m.stored !== null ? (
                                                <span
                                                    className="size-2 shrink-0 rounded-full bg-primary"
                                                    aria-label={t(
                                                        'super.mail_texts.customised',
                                                    )}
                                                />
                                            ) : null}
                                        </button>
                                    ))}
                            </div>
                        ))}
                    </nav>

                    {mail ? (
                        <Editor
                            key={mail.key}
                            mail={mail}
                            onDirtyChange={setDirty}
                        />
                    ) : null}
                </div>
            </div>
        </AppLayout>
    );
}

function Editor({
    mail,
    onDirtyChange,
}: {
    mail: Mail;
    onDirtyChange: (dirty: boolean) => void;
}) {
    const t = useTranslations();
    const initial = mail.stored ?? mail.default;

    const form = useForm({
        subject: initial.subject,
        body: initial.body,
        outro: initial.outro ?? '',
    });

    const subjectRef = useRef<HTMLInputElement>(null);
    const bodyRef = useRef<HTMLTextAreaElement>(null);
    const outroRef = useRef<HTMLTextAreaElement>(null);
    const lastField = useRef<Field>('body');

    const [preview, setPreview] = useState<Preview | null>(null);
    const [previewState, setPreviewState] = useState<
        'loading' | 'ready' | 'failed'
    >('loading');
    const request = useRef(0);

    const { subject, body, outro } = form.data;

    useEffect(() => {
        onDirtyChange(form.isDirty);
    }, [form.isDirty, onDirtyChange]);

    useEffect(() => {
        const id = ++request.current;
        const timer = window.setTimeout(async () => {
            setPreviewState('loading');

            const data = new FormData();
            data.append('subject', subject);
            data.append('body', body);
            data.append('outro', outro);

            try {
                const answer = await postFormJson<Preview>(
                    `/emails/templates/${mail.key}/preview`,
                    data,
                );

                // Only the newest request may paint (see the design page).
                if (id === request.current) {
                    setPreview(answer);
                    setPreviewState('ready');
                }
            } catch {
                if (id === request.current) setPreviewState('failed');
            }
        }, 300);

        return () => window.clearTimeout(timer);
    }, [mail.key, subject, body, outro]);

    function submit(event: FormEvent) {
        event.preventDefault();

        form.put(`/emails/templates/${mail.key}`, {
            preserveScroll: true,
            onSuccess: () => {
                form.setDefaults();
                toast.success(t('super.mail_texts.saved'));
            },
        });
    }

    function reset() {
        if (!window.confirm(t('super.mail_texts.reset_confirm'))) return;

        form.delete(`/emails/templates/${mail.key}`, {
            preserveScroll: true,
            onSuccess: () => {
                const next = {
                    subject: mail.default.subject,
                    body: mail.default.body,
                    outro: mail.default.outro ?? '',
                };
                form.setDefaults(next);
                form.setData(next);
                toast.success(t('super.mail_texts.reset_done'));
            },
        });
    }

    function insertVariable(name: string) {
        const field = lastField.current;
        const element = {
            subject: subjectRef.current,
            body: bodyRef.current,
            outro: outroRef.current,
        }[field];
        const token = `:${name}`;
        const value = form.data[field];

        if (element === null) {
            form.setData(field, `${value}${token}`);
            return;
        }

        const start = element.selectionStart ?? value.length;
        const end = element.selectionEnd ?? value.length;
        form.setData(field, value.slice(0, start) + token + value.slice(end));

        requestAnimationFrame(() => {
            element.focus();
            const caret = start + token.length;
            element.setSelectionRange(caret, caret);
        });
    }

    return (
        <div className="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <form onSubmit={submit} className="flex min-w-0 flex-col gap-5">
                <div className="flex flex-wrap items-center gap-3">
                    <h2 className="text-base font-semibold">
                        {t(`super.mail_texts.names.${mail.key}`)}
                    </h2>
                    <Badge
                        variant={mail.stored !== null ? 'default' : 'secondary'}
                    >
                        {mail.stored !== null
                            ? t('super.mail_texts.customised')
                            : t('super.mail_texts.default')}
                    </Badge>
                    {form.isDirty ? (
                        <span className="text-xs text-muted-foreground">
                            {t('super.mail_texts.unsaved')}
                        </span>
                    ) : null}
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="mt-subject">
                        {t('super.mail_texts.fields.subject')}
                    </Label>
                    <Input
                        id="mt-subject"
                        ref={subjectRef}
                        value={subject}
                        maxLength={255}
                        onFocus={() => (lastField.current = 'subject')}
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
                    <Label htmlFor="mt-body">
                        {t('super.mail_texts.fields.body')}
                    </Label>
                    <textarea
                        id="mt-body"
                        ref={bodyRef}
                        rows={9}
                        maxLength={5000}
                        value={body}
                        onFocus={() => (lastField.current = 'body')}
                        onChange={(e) => form.setData('body', e.target.value)}
                        className={textareaClass}
                    />
                    <p className="text-xs text-muted-foreground">
                        {t('super.mail_texts.hints.body')}
                        {mail.group === 'customer'
                            ? ` ${t('super.mail_texts.hints.body_customer')}`
                            : null}
                    </p>
                    {form.errors.body ? (
                        <p className="text-sm text-destructive">
                            {form.errors.body}
                        </p>
                    ) : null}
                </div>

                {mail.has_outro ? (
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="mt-outro">
                            {t('super.mail_texts.fields.outro')}
                        </Label>
                        <textarea
                            id="mt-outro"
                            ref={outroRef}
                            rows={4}
                            maxLength={2000}
                            value={outro}
                            onFocus={() => (lastField.current = 'outro')}
                            onChange={(e) =>
                                form.setData('outro', e.target.value)
                            }
                            className={textareaClass}
                        />
                        {form.errors.outro ? (
                            <p className="text-sm text-destructive">
                                {form.errors.outro}
                            </p>
                        ) : null}
                    </div>
                ) : !mail.has_button ? (
                    <p className="text-xs text-muted-foreground">
                        {t('super.mail_texts.hints.no_button')}
                    </p>
                ) : null}

                <div className="flex flex-col gap-2">
                    <p className="text-sm font-medium">
                        {t('super.mail_texts.variables')}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {t('super.mail_texts.variables_hint')}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {mail.variables.map((name) => (
                            <button
                                key={name}
                                type="button"
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => insertVariable(name)}
                                className="rounded-md border border-input bg-muted/40 px-2 py-1 font-mono text-xs hover:bg-accent"
                            >
                                :{name}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="flex flex-wrap gap-2 pt-2">
                    <Button
                        type="submit"
                        disabled={form.processing || !form.isDirty}
                    >
                        {t('super.mail_texts.save')}
                    </Button>
                    {form.isDirty ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => form.reset()}
                        >
                            {t('super.mail_texts.discard')}
                        </Button>
                    ) : null}
                    {mail.stored !== null ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={reset}
                            disabled={form.processing}
                        >
                            <RotateCcwIcon className="size-4" />
                            {t('super.mail_texts.reset')}
                        </Button>
                    ) : null}
                </div>
            </form>

            <section className="flex min-w-0 flex-col gap-3">
                <div className="flex flex-col gap-1">
                    <h2 className="text-base font-semibold">
                        {t('super.mail_texts.preview.title')}
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        {t('super.mail_texts.preview.sample_note')}
                    </p>
                </div>

                <p
                    aria-live="polite"
                    className="min-h-5 text-xs text-muted-foreground"
                >
                    {previewState === 'loading'
                        ? t('super.mail_texts.preview.loading')
                        : null}
                    {previewState === 'failed'
                        ? t('super.mail_texts.preview.failed')
                        : null}
                </p>

                {preview !== null ? (
                    <p className="text-sm font-medium break-words">
                        {t('super.mail_texts.preview.subject', {
                            subject: preview.subject,
                        })}
                    </p>
                ) : null}

                <div className="overflow-hidden rounded-xl border border-border bg-white">
                    {/* Sandboxed with nothing allowed: the render has links, and a click must not navigate the panel. */}
                    <iframe
                        title={t('super.mail_texts.preview.frame_title')}
                        sandbox=""
                        srcDoc={preview?.html ?? ''}
                        className="block h-[760px] w-full"
                    />
                </div>
            </section>
        </div>
    );
}
