<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConsentContext;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LegalConsentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded acceptance (SLO-161, GDPR art. 7(1)).
 *
 * Tenant-isolated: a consent given to one tenant is that tenant's evidence, and
 * nobody else's business. The document it points at may be a platform document
 * even so — a tenant accepting the slot4u terms is still that tenant's record.
 *
 * The subject is a user OR an email, never both and never neither: half the
 * entry points are public and produce no user row at all (see the migration).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $legal_document_id
 * @property int|null $user_id
 * @property string|null $email
 * @property ConsentContext $context
 * @property Carbon $accepted_at
 * @property string|null $ip_address
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LegalConsent extends Model
{
    /** @use HasFactory<LegalConsentFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'legal_document_id',
        'user_id',
        'email',
        'context',
        'accepted_at',
        'ip_address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => ConsentContext::class,
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LegalDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'legal_document_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Everything one person accepted, however they were identified at the time.
     *
     * A customer who booked as a guest and later registered with the same address
     * has records under both handles; an art. 15 export that matched only on
     * `user_id` would hand back a partial history and call it complete.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForSubject(Builder $query, ?User $user, ?string $email): void
    {
        $query->where(function (Builder $query) use ($user, $email): void {
            if ($user !== null) {
                $query->orWhere('user_id', $user->getKey());
            }

            if ($email !== null && $email !== '') {
                $query->orWhereRaw('LOWER(email) = ?', [mb_strtolower($email)]);
            }

            // Neither handle given: match nothing rather than everything. An
            // unconstrained OR group would return the whole tenant's consents.
            if ($user === null && ($email === null || $email === '')) {
                $query->whereRaw('1 = 0');
            }
        });
    }
}
