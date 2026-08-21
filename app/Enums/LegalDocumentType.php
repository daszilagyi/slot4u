<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kinds of document a person can be asked to accept (SLO-161).
 *
 * The two exist separately because they bind different parties. Terms are an
 * agreement — the platform's with a tenant, or a tenant's with its customers.
 * A privacy notice is not an agreement at all: it is the controller telling the
 * data subject what it does with their data (GDPR art. 13). Recording an
 * acknowledgement of the notice is what makes art. 7(1) demonstrable; merging it
 * into "terms" would lose which of the two a person actually saw when a version
 * of one changes and the other does not.
 */
enum LegalDocumentType: string
{
    /** Terms of service. */
    case Terms = 'terms';

    /** Privacy notice — who processes what, and on what basis. */
    case Privacy = 'privacy';

    /** Translation key for the human name of this type. */
    public function label(): string
    {
        return 'app.legal.type.'.$this->value;
    }
}
