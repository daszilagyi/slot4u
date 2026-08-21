<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceProvider;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One buyer's identity at an invoicing provider (SLO-167).
 *
 * Tenant-isolated: a partner id is meaningful only inside the tenant's own
 * provider account, and handing one across tenants would be both wrong and a
 * leak.
 *
 * @property int $id
 * @property int $tenant_id
 * @property InvoiceProvider $provider
 * @property string $email
 * @property string $partner_ref
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class InvoicingPartner extends Model
{
    use BelongsToTenant;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'provider',
        'email',
        'partner_ref',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => InvoiceProvider::class,
        ];
    }

    /** Addresses are matched case-insensitively; storing them folded keeps the unique index honest. */
    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
