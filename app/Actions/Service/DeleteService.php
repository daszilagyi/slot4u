<?php

namespace App\Actions\Service;

use App\Models\Service;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a service (SLO-18). Guarded: a service with upcoming bookings cannot
 * be hard-deleted (inactivate instead). The staff/room pivots cascade away on
 * the DB side.
 */
class DeleteService
{
    public function __invoke(Service $service): void
    {
        if ($service->hasFutureBookings()) {
            throw ValidationException::withMessages([
                'delete' => __('app.admin.services.error.has_bookings'),
            ]);
        }

        $service->delete();
    }
}
