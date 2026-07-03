<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which staff may provide a service (docs/02). Both sides are already
        // tenant-scoped through their parent rows, so the pivot needs no
        // tenant_id; cascade on either deletion keeps it clean.
        Schema::create('service_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['service_id', 'staff_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_staff');
    }
};
