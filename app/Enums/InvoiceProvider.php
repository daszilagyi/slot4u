<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The invoicing service that issued a customer invoice (docs/02
 * `invoices.provider`).
 *
 * `sandbox` is the built-in test issuer (SLO-133): it mints numbers and PDFs
 * locally, so the whole issue → storno → retry flow is demoable without an
 * external account. `billingo` is the first real adapter (SLO-167).
 *
 * ⚠️ `szamlazzhu` has NO adapter yet (SLO-134 is parked). It stays in the enum
 * on purpose — an invoice row must be able to name the provider that issued it
 * long after the fact, and the tenant-facing choice is meant to grow. What it
 * must never do is look available: {@see selectable()} keeps it out of the
 * settings form, and the issuer manager refuses it by name rather than falling
 * back to something that would quietly issue the wrong kind of document.
 */
enum InvoiceProvider: string
{
    case Sandbox = 'sandbox';
    case Billingo = 'billingo';
    case SzamlazzHu = 'szamlazzhu';

    /**
     * The providers a tenant may actually pick today.
     *
     * Sandbox is excluded: it is the platform's test issuer, chosen by config on
     * a dev host, not something a real tenant should be able to select — its
     * "invoices" have no legal standing.
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return [self::Billingo];
    }

    /** Whether an adapter exists behind this case. */
    public function isImplemented(): bool
    {
        return $this !== self::SzamlazzHu;
    }

    /** Translation key for the human name of this provider. */
    public function label(): string
    {
        return 'app.invoicing.provider.'.$this->value;
    }
}
