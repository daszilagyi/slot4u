<?php

namespace App\Enums;

enum TenantStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    /**
     * Whether the tenant may access its operational surface (read & write).
     * Suspended tenants are read-only/warning, archived are soft-deleted.
     */
    public function isOperational(): bool
    {
        return match ($this) {
            self::Trial, self::Active => true,
            self::Suspended, self::Archived => false,
        };
    }
}
