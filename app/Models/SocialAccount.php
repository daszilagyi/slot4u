<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SocialProvider;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Google / Facebook identity linked to a user (SLO-251, docs/28).
 *
 * The sign-in callback runs on the central domain with no tenant bound, which
 * the global scope allows (TenantScope is a no-op then) — that is how an
 * identity is found before anyone knows which tenant it belongs to.
 *
 * `email`, `name` and `avatar_url` are the provider's profile as last seen, kept
 * apart from the user's own columns so the provider's data can be deleted on its
 * own (Meta's data-deletion callback, SLO-253) without touching the account.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property SocialProvider $provider
 * @property string $provider_user_id
 * @property string|null $email
 * @property string|null $name
 * @property string|null $avatar_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SocialAccount extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'provider',
        'provider_user_id',
        'email',
        'name',
        'avatar_url',
    ];

    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
