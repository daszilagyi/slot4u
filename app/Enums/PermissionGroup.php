<?php

namespace App\Enums;

/**
 * How the permission catalog is grouped for the tenant's role editor (SLO-141).
 * Purely a presentation grouping — nothing in the authorization chain reads it —
 * but it lives next to the codes rather than in the frontend so a new permission
 * cannot be added without deciding where a tenant admin will look for it.
 *
 * The frontend labels each group from the `admin.roles.group.*` lang keys.
 */
enum PermissionGroup: string
{
    case Bookings = 'bookings';
    case Customers = 'customers';
    case Catalog = 'catalog';
    case Schedule = 'schedule';
    case Insights = 'insights';
    case Communication = 'communication';
    case Administration = 'administration';

    /**
     * The display order of the groups: the daily operational work first, the
     * rarely-touched administrative codes last.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::Bookings,
            self::Customers,
            self::Schedule,
            self::Catalog,
            self::Communication,
            self::Insights,
            self::Administration,
        ];
    }
}
