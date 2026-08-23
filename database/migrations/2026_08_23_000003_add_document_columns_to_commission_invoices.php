<?php

use App\Enums\CommissionInvoiceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The external document behind a commission invoice (SLO-143).
 *
 * `provider`, `provider_ref` and `pdf_path` were already there from J6 and never
 * filled — docs/10 §6.5 step 4 was written as "optionally issue an external
 * invoice" and the optional half never landed. These are the columns that half
 * needs.
 *
 * ⚠️ Deliberately NOT a new value on {@see CommissionInvoiceStatus}.
 * That enum describes the DEBT — issued, paid, overdue, void — and it is what
 * dunning and tenant suspension read. A document that failed to issue must not
 * be able to look like a debt in an unusual state: the tenant still owes the
 * money, the reminder must still go out, and the invoice must still be
 * settleable. So the document's own state lives in its own columns, and
 * `provider_error` non-null simply means "the last attempt was refused, and it
 * can be retried".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_invoices', function (Blueprint $table) {
            // The provider's human-readable number — what an accountant asks for
            // on the phone, and what `provider_ref` (an API id) is not.
            $table->string('number', 64)->nullable()->after('provider_ref');
            $table->text('provider_error')->nullable()->after('pdf_path');

            // The storno is a SECOND document, not an edit of the first. Voiding
            // must never overwrite `pdf_path`: the original invoice went to the
            // tenant and to the books, and an accounting record you can silently
            // replace is not one.
            $table->string('storno_ref', 128)->nullable()->after('provider_error');
            $table->string('storno_pdf_path', 512)->nullable()->after('storno_ref');
        });
    }

    public function down(): void
    {
        Schema::table('commission_invoices', function (Blueprint $table) {
            $table->dropColumn(['number', 'provider_error', 'storno_ref', 'storno_pdf_path']);
        });
    }
};
