import { Head } from '@inertiajs/react';

import MarketingLayout from '@/Layouts/MarketingLayout';
import { useTranslations } from '@/lib/i18n';

type FacebookDataDeletionProps = {
    /** A handled request, when opened from the status URL Meta was given. */
    deletion: {
        code: string;
        completed_at: string | null;
        deleted_accounts: number;
        summary: string;
    } | null;
};

/**
 * Facebook data deletion — the instructions page and, with a confirmation code,
 * the status page Meta's callback points to (SLO-253, docs/28 §6).
 *
 * Both say plainly what is deleted and what is not: the business's own records
 * of the person stay with the business, and the page tells them how to ask for
 * those too.
 */
export default function FacebookDataDeletion({
    deletion,
}: FacebookDataDeletionProps) {
    const t = useTranslations();

    return (
        <MarketingLayout homeLink>
            <Head title={t('facebook_data_deletion.title')} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 py-12 sm:px-6">
                <header className="flex flex-col gap-2">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('facebook_data_deletion.title')}
                    </h1>
                    <p className="text-muted-foreground">
                        {t('facebook_data_deletion.intro')}
                    </p>
                </header>

                {deletion ? (
                    <section
                        role="status"
                        className="flex flex-col gap-2 rounded-xl border border-primary/40 bg-card p-6"
                    >
                        <h2 className="font-semibold">
                            {t('facebook_data_deletion.status_title')}
                        </h2>
                        <p className="font-mono text-sm">
                            {t('facebook_data_deletion.status_code', {
                                code: deletion.code,
                            })}
                        </p>
                        {deletion.completed_at ? (
                            <p className="text-sm">
                                {t('facebook_data_deletion.status_done', {
                                    date: new Date(
                                        deletion.completed_at,
                                    ).toLocaleString(),
                                })}
                            </p>
                        ) : null}
                        <p className="text-sm">{deletion.summary}</p>
                    </section>
                ) : null}

                <section className="flex flex-col gap-2">
                    <h2 className="font-semibold">
                        {t('facebook_data_deletion.what_title')}
                    </h2>
                    <p className="text-sm leading-relaxed">
                        {t('facebook_data_deletion.what')}
                    </p>
                </section>

                <section className="flex flex-col gap-2">
                    <h2 className="font-semibold">
                        {t('facebook_data_deletion.kept_title')}
                    </h2>
                    <p className="text-sm leading-relaxed">
                        {t('facebook_data_deletion.kept')}
                    </p>
                </section>

                <section className="flex flex-col gap-2">
                    <h2 className="font-semibold">
                        {t('facebook_data_deletion.how_title')}
                    </h2>
                    <ul className="flex list-disc flex-col gap-1 pl-5 text-sm leading-relaxed">
                        <li>{t('facebook_data_deletion.how_facebook')}</li>
                        <li>{t('facebook_data_deletion.how_profile')}</li>
                    </ul>
                </section>
            </div>
        </MarketingLayout>
    );
}
