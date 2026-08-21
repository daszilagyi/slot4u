import { Link, usePage } from '@inertiajs/react';

import { useTranslations } from '@/lib/i18n';

/**
 * The acceptance tick box shown on every entry point (SLO-161).
 *
 * The documents come from Inertia's shared props rather than from each page's
 * own props: six forms have to show the same thing, and Fortify's register pages
 * have no controller of ours to pass it from.
 *
 * Renders nothing when the scope has published no documents. A tenant that has
 * not written a privacy notice yet must not have its booking form grow an empty
 * tick box for a document that does not exist.
 */

type Props = {
    checked: boolean;
    onChange: (checked: boolean) => void;
    error?: string;
};

export function LegalConsent({ checked, onChange, error }: Props) {
    const t = useTranslations();
    const { legal } = usePage().props;
    const documents = legal?.documents ?? [];

    if (documents.length === 0) {
        return null;
    }

    return (
        <div className="space-y-1.5">
            <label className="flex items-start gap-2.5 text-sm">
                <input
                    type="checkbox"
                    checked={checked}
                    onChange={(event) => onChange(event.target.checked)}
                    className="mt-0.5 size-4 shrink-0 rounded border-input"
                />
                <span className="text-muted-foreground">
                    {t('legal.accept')}{' '}
                    {documents.map((document, index) => (
                        <span key={document.id}>
                            {index > 0 && ', '}
                            {/* Opens in a new tab on purpose: a half-filled
                                booking form must survive someone reading the
                                thing they are being asked to agree to. */}
                            <Link
                                href={document.href}
                                target="_blank"
                                rel="noopener"
                                className="text-foreground underline underline-offset-2"
                            >
                                {document.title}
                            </Link>
                        </span>
                    ))}
                </span>
            </label>
            {error !== undefined && error !== '' && (
                <p className="text-sm text-destructive">{error}</p>
            )}
        </div>
    );
}
