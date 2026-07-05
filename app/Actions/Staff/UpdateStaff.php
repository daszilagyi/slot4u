<?php

namespace App\Actions\Staff;

use App\Models\Staff;
use Illuminate\Support\Facades\DB;

/**
 * Updates a staff member (SLO-17). If the record has no linked user yet and an
 * email is supplied, the invitation flow runs — so an admin can invite a staff
 * member that was first created as a plain calendar resource. Location
 * assignments (SLO-51) are re-synced when present in the payload.
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

        // Only re-sync locations when the key is present, so a partial update
        // that omits it does not silently clear the assignments.
        $syncLocations = array_key_exists('location_ids', $data);
        $locationIds = array_map('intval', (array) ($data['location_ids'] ?? []));
        unset($data['location_ids']);

        DB::transaction(function () use ($staff, $data, $syncLocations, $locationIds): void {
            $staff->update($data);

            if ($syncLocations) {
                $staff->locations()->sync($locationIds);
            }
        });

        if ($staff->user_id === null && is_string($email) && $email !== '') {
            ($this->inviteStaff)($staff, $email);
        }

        return $staff;
    }
}
