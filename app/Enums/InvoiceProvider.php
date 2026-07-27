<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The invoicing service that issued a customer invoice (docs/02
 * `invoices.provider`).
 *
 * `sandbox` is the built-in test issuer (SLO-133): it mints numbers and PDFs
 * locally, so the whole issue → storno → retry flow is demoable and testable
 * without an external account. The real Számlázz.hu Agent API client lands with
 * SLO-134 behind the same contract.
 */
enum InvoiceProvider: string
{
    case Sandbox = 'sandbox';
    case SzamlazzHu = 'szamlazzhu';
}
