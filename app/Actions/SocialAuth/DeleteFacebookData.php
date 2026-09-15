<?php

declare(strict_types=1);

namespace App\Actions\SocialAuth;

use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\SocialDataDeletionRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Deletes what came from Facebook about one Facebook user (SLO-253, docs/28 §6).
 *
 * Daniel's decision on SLO-250: the Facebook link and the Facebook profile
 * stored with it (id, name, address, picture) go — on every account it was
 * linked to, at every business. The account itself and its bookings stay: they
 * are the business's records, and the business is their controller (docs/19
 * §1), not Facebook and not slot4u. Erasing those is the person's own request to
 * the business (the members area's privacy page), which this does not replace.
 *
 * Idempotent: a repeated or unknown id still gets a request and a code, because
 * Meta expects one either way and "we held nothing" is a valid, final answer.
 */
final class DeleteFacebookData
{
    public function __invoke(string $facebookUserId): SocialDataDeletionRequest
    {
        return DB::transaction(function () use ($facebookUserId): SocialDataDeletionRequest {
            $deleted = SocialAccount::query()
                ->withoutGlobalScopes()
                ->where('provider', SocialProvider::Facebook->value)
                ->where('provider_user_id', $facebookUserId)
                ->delete();

            $request = SocialDataDeletionRequest::query()->create([
                'provider' => SocialProvider::Facebook,
                'provider_user_hash' => hash('sha256', $facebookUserId),
                'confirmation_code' => Str::upper(Str::random(20)),
                'deleted_accounts' => $deleted,
                'completed_at' => Carbon::now(),
            ]);

            Log::info('Facebook data deletion request handled', [
                'confirmation_code' => $request->confirmation_code,
                'deleted_accounts' => $deleted,
            ]);

            return $request;
        });
    }
}
