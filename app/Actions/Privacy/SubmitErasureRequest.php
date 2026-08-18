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

/**
 * A customer asks their tenant to erase them (SLO-159, GDPR art. 17).
 *
 * This only *records* the obligation — slot4u is the processor and must not
 * erase on the controller's behalf. What it guarantees is that the obligation
 * exists exactly once: a customer who clicks twice, or refreshes the confirmation,
 * gets back the request they already have rather than a second row that would
 * show up as a second job in the tenant's queue.
 */
final class SubmitErasureRequest
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(Tenant $tenant, User $user): PrivacyRequest
    {
        $existing = PrivacyRequest::query()
            ->where('user_id', $user->id)
            ->where('type', PrivacyRequestType::Erasure->value)
            ->pending()
            ->first();

        if ($existing instanceof PrivacyRequest) {
            return $existing;
        }

        $request = PrivacyRequest::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => PrivacyRequestType::Erasure,
            'status' => PrivacyRequestStatus::Pending,
        ]);

        $this->audit->record(
            AuditAction::PrivacyErasureRequested,
            $request,
            newValues: ['user_id' => $user->id],
            tenantId: $tenant->id,
        );

        return $request;
    }
}
