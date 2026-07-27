<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TenantDomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A tenant's own hostname for its public booking surface (docs/01, SLO-42).
 *
 * Tenant-isolated via BelongsToTenant for every admin-side query. The host
 * resolver deliberately queries this model with NO tenant bound (the tenant is
 * what it is trying to find), which the global scope allows — see TenantScope.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $domain
 * @property string $verification_token
 * @property Carbon|null $verified_at
 * @property bool $is_primary
 * @property Carbon|null $last_checked_at
 * @property string|null $last_error
 */
class TenantDomain extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TenantDomainFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'domain',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'is_primary' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * The DNS name the ownership TXT record must live on. Kept as a method (not
     * config) so the admin UI, the verifier and the tests cannot drift apart.
     */
    public function verificationRecordName(): string
    {
        return '_slot4u-verify.'.$this->domain;
    }
}
