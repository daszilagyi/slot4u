<?php

declare(strict_types=1);

namespace App\Actions\Domain;

use App\Models\TenantDomain;
use App\Services\Domain\DnsResolver;
use App\Tenancy\CustomDomainResolver;
use Illuminate\Support\Facades\Date;

/**
 * Proves a tenant controls a hostname by looking for its verification token in
 * a TXT record at `_slot4u-verify.{domain}` (SLO-42).
 *
 * Ownership is checked against DNS rather than against the host merely pointing
 * at us: whoever can publish a record in the zone is the party entitled to
 * direct that name here. Verification is idempotent and re-runnable — a tenant
 * that removes the record later keeps working (we do not silently unpublish a
 * live site), but re-verification will then fail and say so.
 */
class VerifyTenantDomain
{
    public function __construct(
        private readonly DnsResolver $dns,
        private readonly CustomDomainResolver $resolver,
    ) {}

    public function __invoke(TenantDomain $domain): bool
    {
        $found = $this->dns->txt($domain->verificationRecordName());

        $matched = in_array($domain->verification_token, array_map(
            // Some resolvers hand back the value still wrapped in quotes.
            fn (string $value): string => trim(trim($value), '"'),
            $found,
        ), true);

        $domain->last_checked_at = Date::now();

        if ($matched) {
            $domain->verified_at ??= Date::now();
            $domain->last_error = null;
        } else {
            $domain->last_error = $found === []
                ? 'txt_record_missing'
                : 'txt_record_mismatch';
        }

        $domain->save();

        // Newly verified: the host may already have been looked up (and missed)
        // while DNS was propagating.
        $this->resolver->forget($domain->domain);

        return $matched;
    }

    /**
     * Whether the domain's DNS currently points at the host we asked for. Purely
     * informational — a tenant may legitimately use an A/ALIAS record, or sit
     * behind its own CDN — so it never blocks verification, it only lets the
     * admin UI explain why a verified domain still does not load.
     */
    public function pointsAtTarget(TenantDomain $domain, string $expected): bool
    {
        return $this->dns->cname($domain->domain) === mb_strtolower($expected, 'UTF-8');
    }
}
