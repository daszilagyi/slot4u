<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom tenant domains (docs/01 multi-tenancy, docs/02, SLO-42).
 *
 * A tenant always keeps its canonical `{slug}.{central}` subdomain; a verified
 * row here lets the same public surface answer on the tenant's own hostname
 * (`booking.acme.hu`) behind `feature_custom_domain`.
 *
 * `domain` is globally unique — a hostname resolves to exactly one tenant, and
 * the uniqueness is what stops a second tenant from claiming a host somebody
 * else already serves. Ownership is proven by a DNS TXT record carrying
 * `verification_token`; until `verified_at` is set the host serves nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Punycode (ASCII) form, lowercased, no port — the shape the Host
            // header arrives in, so lookups are a plain equality match.
            $table->string('domain', 253)->unique();
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            // The host emails, canonical tags and sitemaps should point at.
            // At most one per tenant, enforced in SetPrimaryTenantDomain.
            $table->boolean('is_primary')->default(false);
            // Last verification attempt, surfaced in the admin UI so a tenant
            // can see why DNS is not accepted yet.
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            // The admin list reads a tenant's domains; the resolver reads by
            // `domain` alone (covered by the unique index above).
            $table->index(['tenant_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
    }
};
