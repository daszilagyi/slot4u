<?php

namespace App\Actions\Staff;

use App\Enums\Role;
use App\Models\Staff;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Invites a staff member as a login user (SLO-17): creates a User with the
 * employee role inside the current tenant's team, links it to the staff record,
 * and emails a "set your password" link scoped to the tenant subdomain.
 *
 * The email itself proves address ownership, so the user is created already
 * verified. The password is a throwaway — the invite link (a password-reset
 * token) is the only way in until the employee sets their own.
 */
class InviteStaff
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function __invoke(Staff $staff, string $email): User
    {
        $tenant = $this->tenants->current();

        // Invitations only happen inside tenant context; guard defensively.
        if ($tenant === null) {
            return $staff->user ?? throw new \RuntimeException('Cannot invite staff outside tenant context.');
        }

        $user = DB::transaction(function () use ($staff, $email, $tenant): User {
            $user = User::create([
                'tenant_id' => $tenant->getKey(),
                'name' => $staff->name,
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
                'locale' => $tenant->locale,
            ]);

            // The invitation email proves address ownership; mark verified so the
            // employee is not walled off by MustVerifyEmail. (email_verified_at is
            // not mass-assignable, hence the explicit call.)
            $user->markEmailAsVerified();

            $this->assignEmployeeRole($user, $tenant->getKey());

            $staff->user()->associate($user);
            $staff->save();

            return $user;
        });

        // A password-reset token doubles as the invitation credential; the link
        // targets the tenant subdomain so the employee lands in their own space.
        $token = Password::broker()->createToken($user);
        $user->notify(new StaffInvitationNotification($tenant, $token));

        return $user;
    }

    /**
     * Grant the invited user the employee role within the tenant's team,
     * restoring the previous team context afterwards.
     */
    private function assignEmployeeRole(User $user, int $tenantId): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($tenantId);

        try {
            $user->assignRole(Role::Employee->value);
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }
}
