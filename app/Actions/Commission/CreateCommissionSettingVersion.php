<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\AuditAction;
use App\Models\CommissionSetting;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;

/**
 * Creates a new platform commission configuration version (docs/10 §5.1, §10).
 *
 * Configuration is versioned, never edited: rows are immutable, so a change is a
 * new row with its own `effective_from`. That is what keeps a past period
 * auditable — the recompute resolves the version in force at the period's
 * reference instant and reconstructs the same figures later (§6.4, §12/16).
 *
 * Pricing is money, so the version lands in the audit trail with the values that
 * created it.
 */
final class CreateCommissionSettingVersion
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  Validated attributes (see
     *                                      StoreCommissionSettingRequest).
     */
    public function __invoke(array $data): CommissionSetting
    {
        $setting = new CommissionSetting($data);
        $setting->created_by = Auth::id();
        $setting->save();

        $this->audit->record(
            action: AuditAction::CommissionSettingsCreated,
            auditable: $setting,
            // No old values: a version supersedes rather than replaces, and the
            // one it supersedes stays readable in the history.
            newValues: [
                'free_threshold_minor' => $setting->free_threshold_minor,
                'rate_bps' => $setting->rate_bps,
                'rate_with_integration_bps' => $setting->rate_with_integration_bps,
                'monthly_cap_minor' => $setting->monthly_cap_minor,
                'currency' => $setting->currency,
                'effective_from' => $setting->effective_from->toIso8601String(),
            ],
        );

        return $setting;
    }
}
