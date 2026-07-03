<?php

namespace App\Actions\ServiceCategory;

use App\Models\ServiceCategory;

/**
 * Creates a service category (SLO-18). tenant_id is stamped by BelongsToTenant.
 */
class CreateServiceCategory
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data): ServiceCategory
    {
        return ServiceCategory::create($data);
    }
}
