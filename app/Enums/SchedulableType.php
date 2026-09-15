<?php

namespace App\Enums;

use App\Models\Room;
use App\Models\Staff;

/**
 * The two kinds of resource a schedule belongs to (SLO-19), by their morph
 * alias. One list for the morph map, the form requests and the booking column,
 * so a third kind is added in one place rather than three (SLO-81).
 */
enum SchedulableType: string
{
    case Staff = 'staff';
    case Room = 'room';

    /** The backing table, for tenant-scoped `exists` rules. */
    public function table(): string
    {
        return match ($this) {
            self::Staff => 'staff',
            self::Room => 'rooms',
        };
    }

    /** The column on `bookings` that points at this kind of resource. */
    public function bookingColumn(): string
    {
        return match ($this) {
            self::Staff => 'staff_id',
            self::Room => 'room_id',
        };
    }

    /**
     * @return class-string<Staff|Room>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Staff => Staff::class,
            self::Room => Room::class,
        };
    }

    /**
     * The morph map entries (alias → model).
     *
     * @return array<string, class-string<Staff|Room>>
     */
    public static function morphMap(): array
    {
        $map = [];

        foreach (self::cases() as $type) {
            $map[$type->value] = $type->modelClass();
        }

        return $map;
    }
}
