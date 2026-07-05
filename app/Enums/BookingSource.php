<?php

namespace App\Enums;

/**
 * Where a booking originated (docs/02): the public online flow (M4) or an admin
 * creating it on the customer's behalf.
 */
enum BookingSource: string
{
    case Online = 'online';
    case Admin = 'admin';
}
