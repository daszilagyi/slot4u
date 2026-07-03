<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant staff members (docs/02). A staff row is a bookable calendar
        // resource that MAY be linked to a login user (employee role) via an
        // email invitation, but can also stand alone with no user_id. Tenant-
        // scoped via the BelongsToTenant trait; the tenant_id FK indexes the scope.
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Nullable: staff need not be a login. Deleting the user detaches the
            // staff record (keeps the calendar resource) rather than cascading.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('title')->nullable();
            $table->text('bio')->nullable();
            $table->string('photo')->nullable();
            // Calendar colour as a #RRGGBB hex string.
            $table->string('color', 7)->default('#6366f1');
            $table->boolean('active')->default(true);
            $table->timestamps();

            // A user maps to at most one staff record per tenant.
            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
