<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant's register of data-subject requests (SLO-159, GDPR art. 15 & 17).
 *
 * Both request types land here, but they behave differently: an *export* row is
 * born already resolved (the download happens in the same request — there is
 * nothing for the tenant to decide), while an *erasure* row starts pending and
 * waits for the tenant. The tenant is the controller and slot4u only the
 * processor, so slot4u must not erase on the controller's behalf; the row is
 * what makes the obligation visible and its handling provable.
 *
 * The customer supplies no free text: art. 17 needs no justification in the
 * ordinary case, so asking for one would only create another box of personal
 * data to erase later. The tenant's `resolution_note` is required on a refusal —
 * the customer has to be told why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // The data subject. Survives the erasure it asks for: the user row
            // is anonymised in place, never deleted, so this stays a valid FK
            // and the register keeps proving that the request was honoured.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 16);   // PrivacyRequestType
            $table->string('status', 16); // PrivacyRequestStatus
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The admin queue reads pending requests per tenant; the members
            // page reads one customer's own history.
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_requests');
    }
};
