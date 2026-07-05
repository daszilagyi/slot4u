<?php

namespace App\Enums;

/**
 * A date-specific override to a resource's weekly schedule (docs/02 §Elérhetőség):
 * either a closure (holiday, leave) or an extra opening outside the usual pattern.
 */
enum ScheduleExceptionType: string
{
    case Off = 'off';
    case Extra = 'extra';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
