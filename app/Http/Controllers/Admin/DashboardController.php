<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\BuildTenantDashboard;
use App\Services\Dashboard\TenantDashboard;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant admin landing page (SLO-43, docs/05 M7): a bento grid of today's
 * numbers, today's agenda, the freshest bookings and a month calendar.
 *
 * Behind auth + ensure.user.tenant + ensure.staff (routes/tenant.php) with no
 * `can:` gate of its own — every staff member has a dashboard, it is just narrower
 * for the roles that may see less. {@see BuildTenantDashboard} does the scoping and
 * returns null for the blocks the actor has no permission for; the controller only
 * shapes props. The `dashboard` prop is deliberately a single top-level key so the
 * page can refresh the whole grid with one partial reload when a booking arrives
 * over Reverb (SLO-118).
 */
class DashboardController extends Controller
{
    public function index(Request $request, BuildTenantDashboard $build): Response
    {
        $tenant = app(TenantManager::class)->current();
        abort_if($tenant === null, 404);

        return Inertia::render('Admin/Dashboard', [
            'dashboard' => $this->props($build($tenant, $request->user())),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function props(TenantDashboard $dashboard): array
    {
        return [
            'date' => $dashboard->date,
            'calendar_month' => $dashboard->calendarMonth,
            'timezone' => $dashboard->timezone,
            'currency' => $dashboard->currency,
            'bookings_today' => $dashboard->bookingsToday,
            'confirmed_today' => $dashboard->confirmedToday,
            'revenue_today_minor' => $dashboard->revenueTodayMinor,
            'pending_approval' => $dashboard->pendingApproval,
            'pending_payment' => $dashboard->pendingPayment,
            'customers_total' => $dashboard->customersTotal,
            'customers_new_this_month' => $dashboard->customersNewThisMonth,
            'agenda' => $dashboard->agenda,
            'recent' => $dashboard->recent,
            'calendar' => $dashboard->calendar,
        ];
    }
}
