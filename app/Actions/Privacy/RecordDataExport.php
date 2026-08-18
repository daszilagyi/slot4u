<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Enums\AuditAction;
use App\Enums\PrivacyRequestStatus;
use App\Enums\PrivacyRequestType;
use App\Models\PrivacyRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;

/**
 * Records that a customer took a copy of their data (SLO-159).
 *
 * The export itself needs no approval, but it is still a disclosure of a full
 * personal-data set, and the tenant is the one accountable for it. The row is
 * born completed — there is nothing left to do by the time it exists — so the
 * register reads as a chronology of what left the system rather than as a queue.
 */
final class RecordDataExport
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(Tenant $tenant, User $user): PrivacyRequest
    {
        $request = PrivacyRequest::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => PrivacyRequestType::Export,
            'status' => PrivacyRequestStatus::Completed,
            'resolved_at' => Carbon::now(),
        ]);

        $this->audit->record(
            AuditAction::PrivacyDataExported,
            $request,
            newValues: ['user_id' => $user->id],
            tenantId: $tenant->id,
        );

        return $request;
    }
}
