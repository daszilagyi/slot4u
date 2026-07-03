<?php

namespace App\Actions\ServiceCategory;

use App\Models\ServiceCategory;

/**
 * Deletes a service category (SLO-18). Non-destructive to services: the
 * `services.category_id` FK is nullOnDelete, so a deleted category's services
 * simply become uncategorised rather than cascading away.
 */
class DeleteServiceCategory
{
    public function __invoke(ServiceCategory $category): void
    {
        $category->delete();
    }
}
