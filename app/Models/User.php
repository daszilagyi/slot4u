<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'password',
        'locale',
    ];

    /**
     * Whether the user is a platform super-admin. In the MVP a tenant-less user
     * is a super-admin by invariant (every real tenant user carries a tenant_id);
     * super-admins bypass tenant permission checks via the Gate::before hook in
     * AppServiceProvider. The formal global super-admin role + assignment UI lands
     * with the superadmin panel (SLO-14).
     */
    public function isSuperAdmin(): bool
    {
        return $this->tenant_id === null;
    }

    /**
     * Whether the user is a staff member of their tenant (operates the admin
     * panel) rather than a customer (members area, SLO-94). Super-admins count as
     * staff. The role check is scoped to the user's OWN tenant team and restored
     * afterwards, so it is correct even off the tenant host (login/register run
     * before IdentifyTenant sets the spatie team).
     *
     * Membership is decided by exclusion ({@see Role::isStaffRoleName()}), not by
     * a list of names: since SLO-142 a tenant can define its own roles, and a
     * user holding only a custom role is staff — testing against the three
     * seeded staff names would have locked exactly those users out of the panel.
     */
    public function isStaff(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($this->tenant_id === null) {
            return false;
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($this->tenant_id);

        try {
            // Reading the relation (rather than querying) keeps spatie's
            // per-model memoization, so repeated calls in one request are free.
            $this->loadMissing('roles');

            return $this->roles->contains(
                fn (Model $role): bool => Role::isStaffRoleName((string) $role->getAttribute('name')),
            );
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }

    /**
     * The tenant this user belongs to (null for superadmins).
     *
     * Note: User intentionally does NOT use BelongsToTenant — a global scope
     * would break superadmin access and the login email lookup. Tenant scoping
     * for users is enforced at the policy/query layer.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Bookings this user placed as the customer (SLO-84). Bookings are
     * tenant-isolated (BelongsToTenant), so this only ever spans one tenant.
     *
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'customer_id');
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
