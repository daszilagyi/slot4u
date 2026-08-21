import { usePage } from '@inertiajs/react';

/**
 * The consent fields a form has to carry (SLO-161).
 *
 * Spread into `useForm`'s initial data so the document ids travel with the
 * submission. The server refuses a set that no longer matches what is in force —
 * which is what stops a version published while the form was open from being
 * recorded as accepted by someone who never saw it.
 *
 * Lives here rather than beside the component so the component file exports only
 * components (fast refresh).
 */
export function useLegalConsentFields(): {
    accepted_legal: boolean;
    legal_document_ids: number[];
} {
    const { legal } = usePage().props;

    return {
        accepted_legal: false,
        legal_document_ids: legal?.ids ?? [],
    };
}
