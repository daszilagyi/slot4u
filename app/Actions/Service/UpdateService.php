<?php

namespace App\Actions\Service;

use App\Actions\Service\Concerns\NormalizesServiceData;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Updates a service (SLO-18) and re-syncs its staff/room assignments.
 */
class UpdateService
{
    use NormalizesServiceData;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Service $service, array $data): Service
    {
        $staffIds = array_map('intval', (array) ($data['staff_ids'] ?? []));
        $roomIds = array_map('intval', (array) ($data['room_ids'] ?? []));
        unset($data['staff_ids'], $data['room_ids']);

        return DB::transaction(function () use ($service, $data, $staffIds, $roomIds): Service {
            $service->update($this->normalize($data));
            $service->staff()->sync($staffIds);
            $service->rooms()->sync($roomIds);

            return $service;
        });
    }
}
