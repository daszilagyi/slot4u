<?php

namespace App\Actions\Service;

use App\Actions\Service\Concerns\NormalizesServiceData;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Creates a service (SLO-18) and syncs its staff/room assignments. tenant_id is
 * stamped by BelongsToTenant; mode-irrelevant fields are normalised away.
 */
class CreateService
{
    use NormalizesServiceData;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data): Service
    {
        [$staffIds, $roomIds] = $this->pullAssignments($data);

        return DB::transaction(function () use ($data, $staffIds, $roomIds): Service {
            $service = Service::create($this->normalize($data));
            $service->staff()->sync($staffIds);
            $service->rooms()->sync($roomIds);

            return $service;
        });
    }

    /**
     * Extract and remove the pivot id lists from the attribute payload.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: list<int>, 1: list<int>}
     */
    private function pullAssignments(array &$data): array
    {
        $staffIds = array_map('intval', (array) ($data['staff_ids'] ?? []));
        $roomIds = array_map('intval', (array) ($data['room_ids'] ?? []));
        unset($data['staff_ids'], $data['room_ids']);

        return [$staffIds, $roomIds];
    }
}
