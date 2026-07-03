<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ServiceCategory\CreateServiceCategory;
use App\Actions\ServiceCategory\DeleteServiceCategory;
use App\Actions\ServiceCategory\UpdateServiceCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServiceCategoryRequest;
use App\Models\ServiceCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Service category management (SLO-18). Shares the service.manage grant with
 * services; deleting a category leaves its services uncategorised (nullOnDelete).
 */
class ServiceCategoryController extends Controller
{
    public function store(ServiceCategoryRequest $request, CreateServiceCategory $createCategory): RedirectResponse
    {
        Gate::authorize('create', ServiceCategory::class);

        $createCategory($request->validated());

        return back();
    }

    // $tenant absorbs the subdomain parameter so {category} binds by name.
    public function update(ServiceCategoryRequest $request, string $tenant, ServiceCategory $category, UpdateServiceCategory $updateCategory): RedirectResponse
    {
        Gate::authorize('update', $category);

        $updateCategory($category, $request->validated());

        return back();
    }

    public function destroy(string $tenant, ServiceCategory $category, DeleteServiceCategory $deleteCategory): RedirectResponse
    {
        Gate::authorize('delete', $category);

        $deleteCategory($category);

        return back();
    }
}
