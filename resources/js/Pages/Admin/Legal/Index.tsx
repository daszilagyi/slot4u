import { Head } from '@inertiajs/react';

import AdminLayout from '@/Layouts/AdminLayout';
import LegalDocumentManager, {
    type LegalDocumentRow,
} from '@/components/admin/LegalDocumentManager';
import PageHeader from '@/components/admin/PageHeader';
import { useTranslations } from '@/lib/i18n';

type IndexProps = {
    documents: LegalDocumentRow[];
};

/**
 * The tenant's own terms and privacy notice (SLO-161).
 *
 * The tenant is the controller of its customers' data (docs/19 §1), so the text
 * is the tenant's to write — slot4u supplies the versioning, not the words.
 */
export default function LegalIndex({ documents }: IndexProps) {
    const t = useTranslations();

    return (
        <AdminLayout>
            <Head title={t('legal.admin.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('legal.admin.title')}
                    description={t('legal.admin.description')}
                />

                <LegalDocumentManager
                    documents={documents}
                    endpoint="/settings/legal"
                />
            </div>
        </AdminLayout>
    );
}
