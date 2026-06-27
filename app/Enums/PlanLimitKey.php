<?php

namespace App\Enums;

/**
 * Quantitative plan limit keys (docs/02, docs/03). A key absent from a plan's
 * limits means that resource is unlimited on that plan.
 */
enum PlanLimitKey: string
{
    case MaxAdmins = 'max_admins';
    case MaxEmployees = 'max_employees';
    case MaxCustomers = 'max_customers';
    case MaxLocations = 'max_locations';
    case MaxRooms = 'max_rooms';
}
