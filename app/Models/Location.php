<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant's physical site (docs/02). Tenant-isolated via BelongsToTenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property array<string, string|null>|null $address
 * @property string|null $phone
 * @property int $sort_order
 * @property bool $active
 */
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * tenant_id is intentionally NOT fillable — BelongsToTenant stamps it.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'address',
        'phone',
        'sort_order',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address' => 'array',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
