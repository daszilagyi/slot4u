<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Edge provisioning state for a custom domain (SLO-135).
 *
 * Owning a hostname (SLO-42's TXT check) and being able to SERVE it are two
 * different things: Cloudflare only answers for custom hostnames registered
 * with it, so a verified domain that was never registered returns error 1014
 * instead of the booking page. These columns track that second half — the
 * provider's id for the hostname, whether registration succeeded, and how far
 * the certificate has got — so a failure stays visible and retryable rather
 * than leaving a domain that verified but silently never works.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table) {
            // The provider's own id, kept so a domain can be deprovisioned
            // after its row is gone.
            $table->string('provider_hostname_id', 64)->nullable()->after('verified_at');
            // null = never attempted (unconfigured environment, or not verified yet).
            $table->string('provisioning_status', 32)->nullable()->after('provider_hostname_id');
            // The provider's certificate state, e.g. pending_validation → active.
            $table->string('certificate_status', 32)->nullable()->after('provisioning_status');
            $table->string('provisioning_error', 500)->nullable()->after('certificate_status');
            $table->timestamp('provisioned_at')->nullable()->after('provisioning_error');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->dropColumn([
                'provider_hostname_id',
                'provisioning_status',
                'certificate_status',
                'provisioning_error',
                'provisioned_at',
            ]);
        });
    }
};
