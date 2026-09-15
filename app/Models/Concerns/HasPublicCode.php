<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * A short, non-guessable public `code`, assigned before insert — the key of a
 * customer-facing URL (`/booked/{code}`, `/waitlisted/{code}`).
 *
 * Eight characters from an alphabet without visually ambiguous ones (no
 * 0/O/1/I/L), so it survives being read aloud or retyped from a phone. Unique
 * across the platform, not just the tenant, because the URL is: the retry here
 * handles the (very unlikely) collision, the DB unique index is the backstop.
 *
 * @mixin Model
 */
trait HasPublicCode
{
    private const PUBLIC_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function bootHasPublicCode(): void
    {
        static::creating(function (Model $model): void {
            if (blank($model->getAttribute('code'))) {
                $model->setAttribute('code', static::generateUniqueCode());
            }
        });
    }

    public static function generateUniqueCode(): string
    {
        $max = strlen(self::PUBLIC_CODE_ALPHABET) - 1;

        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::PUBLIC_CODE_ALPHABET[random_int(0, $max)];
            }
        } while (static::query()->withoutGlobalScopes()->where('code', $code)->exists());

        return $code;
    }
}
