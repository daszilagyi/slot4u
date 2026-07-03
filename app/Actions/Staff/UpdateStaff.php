<?php

namespace App\Actions\Staff;

use App\Models\Staff;

/**
 * Updates a staff member (SLO-17). If the record has no linked user yet and an
 * email is supplied, the invitation flow runs — so an admin can invite a staff
 * member that was first created as a plain calendar resource.
 */
class UpdateStaff
{
    public function __construct(private readonly InviteStaff $inviteStaff) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Staff $staff, array $data): Staff
    {
        $email = $data['email'] ?? null;
        unset($data['email']);

        $staff->update($data);

        if ($staff->user_id === null && is_string($email) && $email !== '') {
            ($this->inviteStaff)($staff, $email);
        }

        return $staff;
    }
}
