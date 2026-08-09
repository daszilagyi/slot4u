<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rewrite the phone numbers already in the database to E.164 (SLO-151).
 *
 * Until now every phone field accepted any string, so the stored values are
 * whatever people typed: `06 30 123 4567`, `+36-30/123-4567`, and some that are
 * not numbers at all. Validation only governs what arrives from here on, so
 * without this pass the columns would hold two shapes forever and the eventual
 * SMS integration would have to start with a data cleanup.
 *
 * Conservative on purpose: a value is rewritten only when it parses to a valid
 * number under its own tenant's dialling region. Anything else — junk, or a
 * foreign local number that cannot be told apart from a domestic one — is left
 * exactly as it is, for a human to fix. Nothing is deleted, and re-running the
 * migration is a no-op because E.164 normalizes to itself.
 */
return new class extends Migration
{
    /** Rows read per query — the tables are small, but a launch tenant's are not. */
    private const CHUNK = 500;

    public function up(): void
    {
        // A tenant's dialling region comes from its timezone; archived tenants
        // count too, since their rows are still on disk and may come back.
        $regions = DB::table('tenants')
            ->select('id', 'timezone')
            ->get()
            ->mapWithKeys(fn (object $tenant): array => [
                (int) $tenant->id => PhoneNumber::regionForTimezone(is_string($tenant->timezone) ? $tenant->timezone : null),
            ])
            ->all();

        $fallback = PhoneNumber::regionForTimezone(null);

        foreach ([['users', 'phone'], ['locations', 'phone'], ['bookings', 'guest_phone'], ['quote_requests', 'guest_phone']] as [$table, $column]) {
            $this->normalizeColumn($table, $column, $regions, $fallback);
        }

        $this->normalizeTenantSettings($regions, $fallback);
    }

    public function down(): void
    {
        // Irreversible by nature: the text a visitor originally typed is not kept
        // anywhere, so there is nothing to restore it from.
    }

    /**
     * @param  array<int, string>  $regions
     */
    private function normalizeColumn(string $table, string $column, array $regions, string $fallback): void
    {
        $lastId = 0;

        while (true) {
            $rows = DB::table($table)
                ->select('id', 'tenant_id', $column)
                ->whereNotNull($column)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $value = $row->{$column};

                if (! is_string($value)) {
                    continue;
                }

                $region = $regions[(int) $row->tenant_id] ?? $fallback;
                $e164 = PhoneNumber::toE164($value, $region);

                if ($e164 === null || $e164 === $value) {
                    continue;
                }

                DB::table($table)->where('id', $row->id)->update([$column => $e164]);
            }
        }
    }

    /**
     * The tenant's own public phone number lives in the `settings` JSON blob, so
     * it is read and written whole rather than as a column.
     *
     * @param  array<int, string>  $regions
     */
    private function normalizeTenantSettings(array $regions, string $fallback): void
    {
        $tenants = DB::table('tenants')->select('id', 'settings')->whereNotNull('settings')->get();

        foreach ($tenants as $tenant) {
            $settings = is_string($tenant->settings) ? json_decode($tenant->settings, true) : null;

            if (! is_array($settings) || ! is_string($settings['phone'] ?? null)) {
                continue;
            }

            $e164 = PhoneNumber::toE164($settings['phone'], $regions[(int) $tenant->id] ?? $fallback);

            if ($e164 === null || $e164 === $settings['phone']) {
                continue;
            }

            $settings['phone'] = $e164;

            DB::table('tenants')->where('id', $tenant->id)->update(['settings' => json_encode($settings)]);
        }
    }
};
