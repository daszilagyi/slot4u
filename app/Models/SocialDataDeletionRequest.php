<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SocialProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A provider's request to delete what we hold about one of its users (SLO-253,
 * docs/28 §6). Platform-level: not tenant data, and no BelongsToTenant.
 *
 * @property int $id
 * @property SocialProvider $provider
 * @property string $provider_user_hash sha256 of the provider's user id
 * @property string $confirmation_code
 * @property int $deleted_accounts
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SocialDataDeletionRequest extends Model
{
    protected $fillable = [
        'provider',
        'provider_user_hash',
        'confirmation_code',
        'deleted_accounts',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
            'deleted_accounts' => 'integer',
            'completed_at' => 'datetime',
        ];
    }
}
