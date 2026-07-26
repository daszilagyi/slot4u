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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'trial_ends_at' => 'datetime',
            'branding' => 'array',
            'settings' => 'array',
            // Encrypted at rest: it carries the invoicing provider's API key.
            'invoicing' => 'encrypted:array',
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
