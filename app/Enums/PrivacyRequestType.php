<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a data subject asked for (SLO-159, GDPR art. 15 & 17).
 *
 * The two types differ in who acts, not just in what happens: an export is the
 * processor answering with data it already holds, so the customer gets it
 * immediately; an erasure destroys the controller's records, so only the tenant
 * may execute it.
 */
enum PrivacyRequestType: string
{
    /** Art. 15 — a copy of everything the app stores about the customer. */
    case Export = 'export';

    /** Art. 17 — erase the personal data, keep the anonymised booking history. */
    case Erasure = 'erasure';

    /**
     * Whether submitting the request also completes it. Only the export does:
     * it is served in the same response, so a pending export row would describe
     * an obligation that no longer exists.
     */
    public function isSelfService(): bool
    {
        return $this === self::Export;
    }
}
