import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

import AuthLayout from '@/Layouts/AuthLayout';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';

type ConsentProps = {
    documents: {
        id: number;
        type: 'terms' | 'privacy';
        version: string;
        title: string;
        href: string;
    }[];
};

/**
 * The blocking re-acceptance screen (SLO-161).
 *
 * Deliberately not a dismissible banner: a banner leaves the product usable by
 * someone who has not accepted the terms it is being used under, which is the
 * state this whole feature exists to make impossible.
 *
 * Rendered in AuthLayout rather than the app shell so it does not offer the
 * navigation the user is not allowed to use yet.
 */
export default function Consent({ documents }: ConsentProps) {
    const t = useTranslations();
    const form = useForm({ accepted_legal: false });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/consent');
    }

    return (
        <AuthLayout
            title={t('legal.consent.title')}
            subtitle={t('legal.consent.intro')}
        >
            <Head title={t('legal.consent.title')} />

            <form onSubmit={submit} className="flex flex-col gap-5">
                <ul className="flex flex-col gap-px overflow-hidden rounded-xl border border-border bg-border">
                    {documents.map((document) => (
                        <li
                            key={document.id}
                            className="flex items-center justify-between gap-3 bg-card px-4 py-3 text-sm"
                        >
                            <span className="flex flex-col">
                                <span className="font-medium">
                                    {document.title}
                                </span>
                                <span className="text-muted-foreground">
                                    {t('legal.version', {
                                        version: document.version,
                                    })}
                                </span>
                            </span>
                            <Link
                                href={document.href}
                                target="_blank"
                                rel="noopener"
                                className="shrink-0 underline underline-offset-2"
                            >
                                {t('legal.open')}
                            </Link>
                        </li>
                    ))}
                </ul>

                <label className="flex items-start gap-2.5 text-sm">
                    <input
                        type="checkbox"
                        checked={form.data.accepted_legal}
                        onChange={(event) =>
                            form.setData('accepted_legal', event.target.checked)
                        }
                        className="mt-0.5 size-4 shrink-0 rounded border-input"
                    />
                    <span className="text-muted-foreground">
                        {t('legal.accept')}
                    </span>
                </label>

                {form.errors.accepted_legal !== undefined && (
                    <p className="text-sm text-destructive">
                        {form.errors.accepted_legal}
                    </p>
                )}

                <Button type="submit" disabled={form.processing}>
                    {t('legal.consent.submit')}
                </Button>
            </form>
        </AuthLayout>
    );
}
