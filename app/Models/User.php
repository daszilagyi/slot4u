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
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

/**
 * Larastan cannot infer the date attributes on this model (the casts live in a
 * method and the parent contributes some of them), so they are declared — the
 * same treatment {@see Tenant} needed for its timestamps.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $locale
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $anonymized_at the erasure instant (SLO-159); null while the account holds real personal data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

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
        // ⚠️ The second factor itself (SLO-149). A two-factor secret that reaches
        // an Inertia prop is a second factor anybody who can read the page
        // already has — and the recovery codes are worse, because they do not
        // expire. The TwoFactorAuthenticatable trait hides them too; repeated
        // here so a future edit to that list cannot quietly widen this one.
        'two_factor_secret',
        'two_factor_recovery_codes',
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
            'anonymized_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
