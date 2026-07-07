<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Customer\CreateCustomer;
use App\Actions\Customer\UpdateCustomer;
use App\Enums\BookingStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CustomerRequest;
use App\Models\Booking;
use App\Models\Customer;
use App\Support\CustomerVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin customer management (SLO-84): the tenant's customers (users with the
 * customer role). Behind auth + ensure.user.tenant + can:customer.view|edit
 * (routes/tenant.php). Route-bound {customer} is tenant + role scoped
 * (Customer::resolveRouteBinding → cross-tenant/non-customer 404); an employee is
 * further restricted to their own customers ({@see CustomerVisibility}).
 */
class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        $search = $filters['search'] ?? null;
        $actor = $request->user();

        $query = Customer::tenantScoped()
            ->withCount('bookings')
            ->when($search, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->orderBy('name');

        // Employees see only their own customers (docs/03 "saját ügyfelei").
        CustomerVisibility::apply($query, $actor);

        $customers = $query->paginate(20)->withQueryString()
            ->through(fn (Customer $customer) => $this->summary($customer));

        return Inertia::render('Admin/Customers/Index', [
            'customers' => $customers,
            'filters' => ['search' => $search],
            'can' => ['edit' => (bool) $actor->can(Permission::CustomerEdit->value)],
        ]);
    }

    // $tenant absorbs the subdomain route parameter (before the bound {customer}).
    public function show(Request $request, string $tenant, Customer $customer): Response
    {
        Gate::authorize('view', $customer);

        return Inertia::render('Admin/Customers/Show', [
            'customer' => $this->card($customer),
            'can' => ['edit' => (bool) $request->user()->can(Permission::CustomerEdit->value)],
        ]);
    }

    public function store(CustomerRequest $request, CreateCustomer $create): RedirectResponse
    {
        Gate::authorize('create', Customer::class);

        $create($request->validated());

        return back();
    }

    public function update(CustomerRequest $request, string $tenant, Customer $customer, UpdateCustomer $update): RedirectResponse
    {
        Gate::authorize('update', $customer);

        $update($customer, $request->validated());

        return back();
    }

    /**
     * Row DTO for the list.
     *
     * @return array<string, mixed>
     */
    private function summary(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'bookings_count' => (int) $customer->bookings_count,
            'created_at' => $customer->created_at?->toIso8601String(),
        ];
    }

    /**
     * Customer card DTO: profile + aggregates + recent bookings (N+1-safe eager
     * load). `total_spend_minor` sums completed bookings (delivered/settled money);
     * `bookings_count` counts every booking.
     *
     * @return array<string, mixed>
     */
    private function card(Customer $customer): array
    {
        $recent = $customer->bookings()
            ->with(['service:id,name', 'staff:id,name'])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'created_at' => $customer->created_at?->toIso8601String(),
            'bookings_count' => $customer->bookings()->count(),
            'completed_count' => $customer->bookings()->where('status', BookingStatus::Completed->value)->count(),
            'total_spend_minor' => (int) $customer->bookings()->where('status', BookingStatus::Completed->value)->sum('price_minor'),
            'currency' => $recent->first()->currency ?? 'HUF',
            'recent_bookings' => $recent->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'code' => $booking->code,
                'service' => $booking->service?->name,
                'staff' => $booking->staff?->name,
                'status' => $booking->status->value,
                'starts_at' => $booking->starts_at?->toIso8601String(),
                'price_minor' => (int) $booking->price_minor,
                'currency' => $booking->currency,
            ])->values(),
        ];
    }
}
