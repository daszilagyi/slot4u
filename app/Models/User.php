<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
