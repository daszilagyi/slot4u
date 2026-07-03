<?php

namespace App\Actions\ServiceCategory;

use App\Models\ServiceCategory;

/**
 * Updates a service category (SLO-18).
 */
class UpdateServiceCategory
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(ServiceCategory $category, array $data): ServiceCategory
    {
        $category->update($data);

        return $category;
    }
}
