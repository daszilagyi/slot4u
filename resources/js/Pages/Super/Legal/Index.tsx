import { Head } from '@inertiajs/react';

import AppLayout from '@/Layouts/AppLayout';
import LegalDocumentManager, {
    type LegalDocumentRow,
} from '@/components/admin/LegalDocumentManager';
import { useTranslations } from '@/lib/i18n';

type IndexProps = {
    documents: LegalDocumentRow[];
};

/**
 * The platform's own terms and privacy notice (SLO-161) — what a company accepts
 * when it signs up for slot4u.
 *
 * ⚠️ Publishing a version here sends every tenant admin on the platform through
 * the re-acceptance screen on their next request. That is the point, and the
 * reason the effective date is settable: announce it, then let it take effect.
 */
export default function SuperLegalIndex({ documents }: IndexProps) {
    const t = useTranslations();

    return (
        <AppLayout platformAccent>
            <Head title={t('legal.super.title')} />

            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-8 sm:px-6">
                <header className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('legal.super.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('legal.super.description')}
                    </p>
                </header>

                <LegalDocumentManager documents={documents} endpoint="/legal" />
            </div>
        </AppLayout>
    );
}
