<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A platform-wide setting only the superadmin changes (SLO-245), keyed by name.
 *
 * ⚠️ Deliberately NOT BelongsToTenant, like AuditLog and the platform legal
 * documents: these values exist to be the same for every tenant (the look of
 * every system email is the first). Read in tenant context — a tenant's booking
 * mail renders with it — so a tenant scope would hide the row exactly where it
 * is needed.
 *
 * @property int $id
 * @property string $key
 * @property array<string, mixed> $value
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'updated_by' => 'integer',
        ];
    }
}
