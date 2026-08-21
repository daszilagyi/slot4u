<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LegalDocumentType;
use Database\Factories\LegalDocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One version of one document a person can be asked to accept (SLO-161).
 *
 * Note: like {@see User}, this intentionally does NOT use `BelongsToTenant`. The
 * global scope would hide exactly the rows that must stay visible — the platform
 * documents, whose `tenant_id` is null — and the trait's creating hook would
 * stamp the current tenant onto one, quietly turning a platform document into a
 * tenant's. Scoping is done explicitly through {@see scopePlatform} and
 * {@see scopeOwnedBy}, and every read goes through one registry service so there
 * is a single place to get it right.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property LegalDocumentType $type
 * @property string $version
 * @property string $title
 * @property string|null $body
 * @property string|null $url
 * @property Carbon $effective_from
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LegalDocument extends Model
{
    /** @use HasFactory<LegalDocumentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'type',
        'version',
        'title',
        'body',
        'url',
        'effective_from',
        'created_by_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LegalDocumentType::class,
            'effective_from' => 'datetime',
        ];
    }

    /**
     * The tenant whose document this is; null for a platform document.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return HasMany<LegalConsent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(LegalConsent::class);
    }

    /**
     * The platform's own documents — what a tenant accepts when it signs up.
     *
     * @param  Builder<self>  $query
     */
    public function scopePlatform(Builder $query): void
    {
        $query->whereNull('tenant_id');
    }

    /**
     * One tenant's own documents — what its customers accept.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOwnedBy(Builder $query, Tenant|int $tenant): void
    {
        $query->where('tenant_id', $tenant instanceof Tenant ? $tenant->getKey() : $tenant);
    }

    /**
     * Versions already in force. A future `effective_from` is a published draft:
     * announced, visible to its author, and not yet the one anybody is asked to
     * accept.
     *
     * @param  Builder<self>  $query
     */
    public function scopeEffective(Builder $query): void
    {
        $query->where('effective_from', '<=', now());
    }

    /** Whether this version is the text itself rather than a link to it. */
    public function isInline(): bool
    {
        return $this->body !== null && $this->body !== '';
    }

    /** Whether any acceptance has been recorded against this exact version. */
    public function hasConsents(): bool
    {
        return $this->consents()->exists();
    }
}
