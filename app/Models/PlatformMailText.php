<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The superadmin's wording of one system email in one locale (SLO-246).
 *
 * ⚠️ Deliberately NOT BelongsToTenant, like PlatformSetting: a customer mail
 * renders in tenant context on a queue worker and has to find the platform's
 * base text there. A tenant's own wording lives in MessageTemplate instead.
 *
 * @property int $id
 * @property string $key
 * @property string $locale
 * @property string $subject
 * @property string|null $greeting
 * @property string $body
 * @property string|null $action_label
 * @property string|null $outro
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PlatformMailText extends Model
{
    protected $fillable = ['key', 'locale', 'subject', 'greeting', 'body', 'action_label', 'outro', 'updated_by'];

    protected function casts(): array
    {
        return [
            'updated_by' => 'integer',
        ];
    }
}
