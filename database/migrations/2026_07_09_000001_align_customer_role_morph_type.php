<?php

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aligns existing spatie role/permission rows written for the Customer morph
 * type with User's (SLO-95). Customers share the `users` table and log in as
 * User, so {@see Customer::getMorphClass()} now returns User's morph
 * — but rows created before that fix (guest bookings SLO-31, admin roster
 * SLO-84) still carry `model_type = 'App\Models\Customer'` and would be invisible
 * to hasRole()/ensure.customer and the admin customer roster after deploy.
 */
return new class extends Migration
{
    private const OLD_MORPH = 'App\\Models\\Customer';

    public function up(): void
    {
        $userMorph = (new User)->getMorphClass();

        foreach (['model_has_roles', 'model_has_permissions'] as $table) {
            DB::table($table)
                ->where('model_type', self::OLD_MORPH)
                ->update(['model_type' => $userMorph]);
        }
    }

    public function down(): void
    {
        // One-way data alignment: there is no meaningful way to tell which User
        // morph rows were originally Customer, and both now resolve identically.
    }
};
