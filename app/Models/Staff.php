<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant staff member (docs/02): a bookable calendar resource that may be
 * linked to a login user (employee role) via email invitation, or stand alone
 * with no user_id. Tenant-isolated via BelongsToTenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $title
 * @property string|null $bio
 * @property string|null $photo
 * @property string $color
 * @property bool $active
 */
class Staff extends Model
{
    /** @use HasFactory<StaffFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Eloquent would pluralise "Staff" to "staves"; pin the real table name.
     *
     * @var string
     */
    protected $table = 'staff';

    /**
     * tenant_id and user_id are set by the application (BelongsToTenant stamps
     * the tenant; the invite flow links the user) — never by request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'title',
        'bio',
        'photo',
        'color',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * The login user linked to this staff member (null for a pure calendar
     * resource). User intentionally has no tenant global scope, so this reads
     * across the scope safely for the current tenant's staff.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Locations this staff member works at (docs/02 staff_locations, SLO-51). A
     * multi-location staff can have per-location availability via
     * schedules.location_id. Both sides are tenant-scoped through their parents.
     *
     * @return BelongsToMany<Location, $this>
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'staff_locations');
    }

    /**
     * Whether the staff member has upcoming bookings that block hard deletion
     * (they must be inactivated instead). The bookings table arrives with the
     * booking engine (M3); until then staff can never have a booking, so false.
     */
    public function hasFutureBookings(): bool
    {
        if (! Schema::hasTable('bookings')) {
            return false;
        }

        // Status literals mirror docs/04; M3 replaces them with the BookingStatus enum.
        return DB::table('bookings')
            ->where('staff_id', $this->id)
            ->where('starts_at', '>', now())
            ->whereNotIn('status', ['canceled', 'no_show'])
            ->exists();
    }
}
