<?php

declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Enums\InvoiceProvider;
use App\Models\Tenant;
use App\Services\Invoicing\Contracts\InvoiceIssuer;
use App\Services\Invoicing\Issuers\BillingoInvoiceIssuer;
use App\Services\Invoicing\Issuers\SandboxInvoiceIssuer;
use App\Settings\TenantInvoicingSettings;
use RuntimeException;

/**
 * Resolves the {@see InvoiceIssuer} behind a provider key (SLO-133, SLO-167).
 *
 * The choice is the TENANT's (SLO-167): it lives in the tenant's encrypted
 * `invoicing` settings, and `config/invoicing.php` is only the fallback for a
 * tenant that has not chosen — which on a dev host is the sandbox. It used to be
 * the other way round, one value for the whole platform, which could never have
 * supported two tenants invoicing through different services.
 *
 * Deliberately not final: this is the seam where a provider is swapped, and the
 * test suite substitutes a refusing issuer here to exercise the failure path.
 */
class InvoiceIssuerManager
{
    public function __construct(
        private readonly SandboxInvoiceIssuer $sandbox,
        private readonly BillingoInvoiceIssuer $billingo,
    ) {}

    /** The platform fallback, for a tenant that has chosen nothing. */
    public function default(): InvoiceIssuer
    {
        $configured = InvoiceProvider::tryFrom((string) config('invoicing.default'));

        if ($configured === null) {
            throw new RuntimeException('Unknown invoicing provider configured: '.(string) config('invoicing.default'));
        }

        return $this->for($configured);
    }

    /**
     * The issuer this tenant invoices through.
     *
     * ⚠️ A demo tenant is pinned to the sandbox before its own choice is even
     * read (SLO-182, docs/20 §3.1). The seeded demo data contains paid bookings,
     * and an invoice is raised off a settled payment — against a real Billingo
     * account that would number those documents into slot4u's own block and file
     * them with the tax authority. A fabricated customer's invoice is not
     * something a storno makes fully undone, so the branch is closed here rather
     * than guarded at each call site.
     */
    public function forTenant(Tenant $tenant): InvoiceIssuer
    {
        if ($tenant->is_demo) {
            return $this->sandbox;
        }

        $chosen = TenantInvoicingSettings::fromArray($tenant->invoicing)->provider;

        return $chosen === null ? $this->default() : $this->for($chosen);
    }

    /**
     * The issuer for an existing invoice (a row can outlive the driver it was
     * issued with).
     *
     * A provider with no adapter throws by name. That is the point: the
     * alternative — falling back to the sandbox — would issue a document with no
     * legal standing and report success, which is worse than any failure.
     */
    public function for(InvoiceProvider $provider): InvoiceIssuer
    {
        return match ($provider) {
            InvoiceProvider::Sandbox => $this->sandbox,
            InvoiceProvider::Billingo => $this->billingo,
            // SLO-134 is parked; the case stays so old rows can name it.
            InvoiceProvider::SzamlazzHu => throw new RuntimeException(
                'No invoicing adapter for provider: '.$provider->value
            ),
        };
    }
}
