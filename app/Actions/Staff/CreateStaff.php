<?php

namespace App\Actions\Staff;

use App\Enums\PlanLimitKey;
use App\Models\Staff;
use App\Services\Plan\PlanLimitService;
use Illuminate\Validation\ValidationException;

/**
 * Creates a tenant staff member, enforcing the base plan's max_employees limit
 * (SLO-17). tenant_id is stamped by BelongsToTenant, not the request. An
 * optional email triggers the invitation flow via {@see InviteStaff}.
 */
class CreateStaff
{
    public function __construct(
        private readonly PlanLimitService $limits,
        private readonly InviteStaff $inviteStaff,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data): Staff
    {
        if (! $this->limits->withinLimit(PlanLimitKey::MaxEmployees, Staff::count())) {
            throw ValidationException::withMessages([
                'name' => __('app.admin.staff.error.limit_employees'),
            ]);
        }

        $email = $this->pullEmail($data);

        $staff = Staff::create($data);

        if ($email !== null) {
            ($this->inviteStaff)($staff, $email);
        }

        return $staff;
    }

    /**
     * Extract the (optional) invitation email from the payload so it is not
     * mass-assigned onto the Staff model.
     *
     * @param  array<string, mixed>  $data
     */
    private function pullEmail(array &$data): ?string
    {
        $email = $data['email'] ?? null;
        unset($data['email']);

        return is_string($email) && $email !== '' ? $email : null;
    }
}
