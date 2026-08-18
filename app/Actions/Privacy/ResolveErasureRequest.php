<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Enums\AuditAction;
use App\Enums\PrivacyRequestStatus;
use App\Models\PrivacyRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Privacy\AnonymizeCustomer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The tenant's decision on an erasure request (SLO-159).
 *
 * Both outcomes live in one class because they are one decision with two
 * branches, and both must leave the same trace: who decided, when, and — for a
 * refusal — why. Art. 12 (4) obliges the controller to tell the subject the
 * reasons for not acting, so a refusal without a note is not a valid outcome
 * and the FormRequest requires one.
 *
 * The status flip and the erasure share a transaction: a request marked
 * completed whose data survived would be a false compliance record, which is
 * worse than an unresolved request.
 */
final class ResolveErasureRequest
{
    public function __construct(
        private readonly AnonymizeCustomer $anonymizer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Execute the erasure and close the request.
     */
    public function approve(PrivacyRequest $request, Tenant $tenant, User $actor): void
    {
        $subject = $request->user;

        DB::transaction(function () use ($request, $tenant, $actor, $subject): void {
            $this->anonymizer->erase($subject, $tenant);

            $request->forceFill([
                'status' => PrivacyRequestStatus::Completed,
                'resolved_at' => Carbon::now(),
                'resolved_by_id' => $actor->id,
            ])->save();
        });

        // Outside the transaction's business logic but with the same tenant id:
        // the trail records *that* the erasure happened and on which request —
        // never the values it destroyed, which would put them straight back.
        $this->audit->record(
            AuditAction::PrivacyErasureCompleted,
            $request,
            newValues: ['user_id' => $subject->id],
            tenantId: $tenant->id,
        );
    }

    /**
     * Refuse the request, on the record, with the reason the subject must be given.
     */
    public function reject(PrivacyRequest $request, Tenant $tenant, User $actor, string $reason): void
    {
        $request->forceFill([
            'status' => PrivacyRequestStatus::Rejected,
            'resolution_note' => $reason,
            'resolved_at' => Carbon::now(),
            'resolved_by_id' => $actor->id,
        ])->save();

        $this->audit->record(
            AuditAction::PrivacyErasureRejected,
            $request,
            newValues: ['user_id' => $request->user_id, 'reason' => $reason],
            tenantId: $tenant->id,
        );
    }
}
