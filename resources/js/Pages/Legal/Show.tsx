import { Head } from '@inertiajs/react';

import PublicLayout from '@/Layouts/PublicLayout';
import { useTranslations } from '@/lib/i18n';

type ShowProps = {
    document: {
        type: 'terms' | 'privacy';
        version: string;
        title: string;
        body: string;
        effectiveFrom: string;
    };
};

/**
 * One version of one legal document (SLO-161).
 *
 * Reachable without an account, because nobody can consent to a text they are
 * not allowed to read — and superseded versions stay reachable too: a consent
 * record naming version 1.2 proves nothing if 1.2 can no longer be seen.
 *
 * The body renders as pre-wrapped plain text rather than as HTML. A legal text
 * arrives from a tenant admin's textarea, and rendering that as markup would put
 * an XSS hole in the one page every visitor is told to open.
 */
export default function Show({ document }: ShowProps) {
    const t = useTranslations();

    const effective = new Date(document.effectiveFrom).toLocaleDateString();

    return (
        <PublicLayout>
            <Head title={document.title} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-12 sm:px-6">
                <header className="flex flex-col gap-1.5">
                    <p className="text-sm text-muted-foreground">
                        {t(`legal.type.${document.type}`)}
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {document.title}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('legal.version', { version: document.version })}
                        {' · '}
                        {t('legal.effective_from', { date: effective })}
                    </p>
                </header>

                <article className="whitespace-pre-wrap rounded-xl border border-border bg-card p-5 text-sm leading-relaxed">
                    {document.body}
                </article>
            </div>
        </PublicLayout>
    );
}
