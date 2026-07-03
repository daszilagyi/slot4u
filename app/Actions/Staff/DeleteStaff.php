<?php

namespace App\Actions\Staff;

use App\Models\Staff;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a staff member (SLO-17). Guarded: a staff member with upcoming
 * bookings cannot be hard-deleted (inactivate instead). The linked login user
 * (if any) is intentionally kept — only the calendar resource is removed; the
 * user_id FK is nullOnDelete on the users side, not the reverse.
 */
class DeleteStaff
{
    public function __invoke(Staff $staff): void
    {
        if ($staff->hasFutureBookings()) {
            throw ValidationException::withMessages([
                'delete' => __('app.admin.staff.error.has_bookings'),
            ]);
        }

        $staff->delete();
    }
}
