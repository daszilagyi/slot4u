<?php

namespace App\Enums;

/**
 * Room resource kind (docs/04 §4). In the MVP a rentable "resource" is always a
 * room record; equipment is modelled as a room with type=equipment rather than
 * a separate table.
 */
enum RoomType: string
{
    case Room = 'room';
    case Equipment = 'equipment';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
