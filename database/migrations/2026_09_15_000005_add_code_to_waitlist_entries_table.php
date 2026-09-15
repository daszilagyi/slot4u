<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A public code for a waitlist place (SLO-103), like `bookings.code`.
 *
 * The join confirmation used to live in a one-off session flash: a refresh, a
 * bookmark or a shared link sent the person back to the home page with no trace
 * of their place. `/waitlisted/{code}` is the durable address instead.
 *
 * Existing rows get a code here, so the column can be NOT NULL + unique for
 * every row the application will ever read.
 */
return new class extends Migration
{
    /** Same alphabet as booking codes: no 0/O/1/I/L. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->string('code', 8)->nullable()->after('id');
        });

        $taken = [];

        DB::table('waitlist_entries')->whereNull('code')->orderBy('id')->select('id')
            ->each(function (object $row) use (&$taken): void {
                do {
                    $code = '';
                    for ($i = 0; $i < 8; $i++) {
                        $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
                    }
                } while (isset($taken[$code]));

                $taken[$code] = true;

                DB::table('waitlist_entries')->where('id', $row->id)->update(['code' => $code]);
            });

        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->string('code', 8)->nullable(false)->change();
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
