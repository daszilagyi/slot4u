<?php

namespace App\Models;

use App\Enums\RoomType;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A bookable resource (room or equipment) inside a location (docs/02, docs/04 §4).
 * Tenant-isolated via BelongsToTenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $location_id
 * @property string $name
 * @property RoomType $type
 * @property int $capacity
 * @property string|null $description
 * @property bool $active
 */
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'location_id',
        'name',
        'type',
        'capacity',
        'description',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RoomType::class,
            'capacity' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Whether the room has upcoming bookings that block hard deletion (it must be
     * inactivated instead). The bookings table arrives with the booking engine
     * (M3); until then a room can never have a booking, so this is false.
     */
    public function hasFutureBookings(): bool
    {
        if (! Schema::hasTable('bookings')) {
            return false;
        }

        // Status literals mirror docs/04; M3 replaces them with the BookingStatus enum.
        return DB::table('bookings')
            ->where('room_id', $this->id)
            ->where('starts_at', '>', now())
            ->whereNotIn('status', ['canceled', 'no_show'])
            ->exists();
    }
}
