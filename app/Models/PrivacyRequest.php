<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PrivacyRequestStatus;
use App\Enums\PrivacyRequestType;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PrivacyRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One data-subject request in a tenant's register (SLO-159).
 *
 * Tenant-isolated through {@see BelongsToTenant}, so a foreign id 404s on route
 * binding like every other tenant model — the customer's identity is not
 * something to probe across tenants.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property PrivacyRequestType $type
 * @property PrivacyRequestStatus $status
 * @property string|null $resolution_note
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PrivacyRequest extends Model
{
    /** @use HasFactory<PrivacyRequestFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'type',
        'status',
        'resolution_note',
        'resolved_at',
        'resolved_by_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PrivacyRequestType::class,
            'status' => PrivacyRequestStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * The data subject. Not a {@see Customer}: after an erasure the row is still
     * a user of this tenant, and binding through the customer scope would make
     * the register unreadable exactly for the requests that were honoured.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The staff member who executed or refused the request.
     *
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /**
     * Requests still awaiting the tenant's decision.
     *
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', PrivacyRequestStatus::Pending->value);
    }
}
