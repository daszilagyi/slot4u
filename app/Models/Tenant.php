<?php

namespace App\Models;

use App\Enums\TenantStatus;
use App\Observers\TenantObserver;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property TenantStatus $status
 * @property string $timezone
 * @property string $locale
 * @property Carbon|null $trial_ends_at
 * @property array<string, mixed>|null $branding
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $invoicing seller details + provider API key (encrypted at rest)
 * @property array<string, mixed>|null $analytics the tenant's own GA4 / Meta measurement config (encrypted at rest)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at the archive instant — the platform's only churn timestamp (SLO-138)
 * @property Carbon|null $purged_at when the retention sweep erased the archived tenant's personal data (SLO-160)
 */
#[ObservedBy([TenantObserver::class])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'status',
        'timezone',
        'locale',
        'trial_ends_at',
        'branding',
        'settings',
        // NOTE: `invoicing` holds a provider credential and is deliberately NOT
        // fillable — it is written through UpdateTenantInvoicing only (SLO-133).
        // `analytics` is out for the same reason (SLO-56): the Conversions API
        // access token lives in it.
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'trial_ends_at' => 'datetime',
            'purged_at' => 'datetime',
            'branding' => 'array',
            'settings' => 'array',
            // Encrypted at rest: it carries the invoicing provider's API key.
            'invoicing' => 'encrypted:array',
            // Same treatment (SLO-56): the measurement ids are public, but they
            // share the column with the Conversions API access token.
            'analytics' => 'encrypted:array',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
