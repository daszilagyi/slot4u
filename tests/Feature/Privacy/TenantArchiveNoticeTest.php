<?php

use App\Actions\Tenant\ChangeTenantStatus;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantArchivedNotification;
use App\Tenancy\TenantManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

/*
 * The archive notice (SLO-160, docs/19 §7).
 *
 * The 90-day grace period is only defensible if the tenant knows about it: a
 * controller that is never told the deadline cannot do the one thing the window
 * exists for — take its own records with it. So the notice is not a courtesy,
 * it is what makes the retention policy lawful, and it is wired into the Action
 * rather than the controller so no future entry point can archive silently.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-08-19 09:00:00');
    $this->seed(PermissionSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** @return array{Tenant, User} */
function noticeFixture(): array
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'Europe/Budapest']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole(Role::TenantAdmin->value);

    return [$tenant, $admin];
}

it('tells the tenant admins the retention deadline when the tenant is archived', function () {
    Notification::fake();
    [$tenant, $admin] = noticeFixture();

    app(ChangeTenantStatus::class)($tenant, TenantStatus::Archived);

    Notification::assertSentTo($admin, TenantArchivedNotification::class);
});

it('names the purge date in the tenant timezone', function () {
    Notification::fake();
    [$tenant, $admin] = noticeFixture();

    app(ChangeTenantStatus::class)($tenant, TenantStatus::Archived);

    Notification::assertSentTo($admin, TenantArchivedNotification::class, function ($notification) use ($admin) {
        $body = implode(' ', $notification->toMail($admin)->introLines);

        // 2026-08-19 + 90 days. A deadline the reader cannot check against a
        // calendar is not a deadline.
        return str_contains($body, Carbon::parse('2026-11-17')->isoFormat('LL'));
    });
});

it('says the invoices are kept and how to still get a copy of the data', function () {
    Notification::fake();
    [$tenant, $admin] = noticeFixture();

    app(ChangeTenantStatus::class)($tenant, TenantStatus::Archived);

    Notification::assertSentTo($admin, TenantArchivedNotification::class, function ($notification) use ($admin) {
        $body = implode(' ', $notification->toMail($admin)->introLines);

        // The mail points at slot4u rather than a self-service link: archiving
        // soft-deletes the tenant, so its own subdomain 404s from that moment.
        return str_contains($body, trans('app.mail.tenant_archived.kept'))
            && str_contains($body, trans('app.mail.tenant_archived.export'));
    });
});

it('does not notify on suspension or activation', function () {
    Notification::fake();
    [$tenant, $admin] = noticeFixture();

    app(ChangeTenantStatus::class)($tenant, TenantStatus::Suspended);
    app(ChangeTenantStatus::class)($tenant, TenantStatus::Active);

    Notification::assertNotSentTo($admin, TenantArchivedNotification::class);
});

it('does not send a second, later deadline when an archived tenant is archived again', function () {
    Notification::fake();
    [$tenant, $admin] = noticeFixture();

    app(ChangeTenantStatus::class)($tenant, TenantStatus::Archived);
    app(ChangeTenantStatus::class)($tenant, TenantStatus::Archived);

    // The sweep still measures from the original `deleted_at`, so a second
    // notice would state a deadline that is simply not true.
    Notification::assertSentToTimes($admin, TenantArchivedNotification::class, 1);
});
