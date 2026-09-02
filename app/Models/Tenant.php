<?php

namespace App\Models;

use App\Enums\TenantStatus;
use App\Observers\TenantObserver;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property TenantStatus $status
 * @property bool $is_demo a sales-demo workspace: no real mail, payment or invoice ever leaves it (SLO-182)
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
        //
        // `is_demo` is out on the opposite reasoning (SLO-182): it does not hold
        // a secret, it REMOVES restrictions no other tenant may remove — a demo
        // tenant is exempt from the billing close and vanishes from the platform
        // statistics. Mass-assignable, it would be one stray request payload away
        // from a paying tenant that stops being invoiced and stops being counted.
        // The demo seeders set it explicitly on a model they just built.
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'is_demo' => 'boolean',
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

    /**
     * Only the sales-demo workspaces (SLO-182).
     *
     * @param  Builder<Tenant>  $query
     */
    public function scopeDemo(Builder $query): void
    {
        $query->where('is_demo', true);
    }

    /**
     * Everything a real customer is (SLO-182) — the scope the platform-wide
     * statistics read through, so a demo tenant never lands in a business number.
     *
     * @param  Builder<Tenant>  $query
     */
    public function scopeExcludingDemo(Builder $query): void
    {
        $query->where('is_demo', false);
    }

    /**
     * The ids of every demo tenant, as a subquery for the tenant-owned tables
     * that have no `is_demo` of their own (bookings, billing periods).
     *
     * ⚠️ Trashed rows included, deliberately. Archiving a tenant soft-deletes it,
     * and an archived demo tenant is still a demo tenant: leaving it out here
     * would let its bookings reappear in the platform's growth series the moment
     * it was archived — the one moment the series looks hardest at it.
     */
    public static function demoIdQuery(): QueryBuilder
    {
        return self::query()
            ->withTrashed()
            ->demo()
            ->select('id')
            ->toBase();
    }
}
