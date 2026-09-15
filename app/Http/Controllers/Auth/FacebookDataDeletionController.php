<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\SocialAuth\DeleteFacebookData;
use App\Enums\SocialProvider;
use App\Http\Controllers\Controller;
use App\Models\SocialDataDeletionRequest;
use App\Services\SocialAuth\FacebookSignedRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Meta's "User data deletion" callback and the pages it points people to
 * (SLO-253, docs/28 §6). Central domain only — it is one URL in the Meta app
 * settings, like the OAuth callback.
 *
 * Meta calls the endpoint when someone removes the app from their Facebook
 * settings, and without it the app cannot go Live. No session, no CSRF token:
 * the caller is authenticated by the `signed_request`'s HMAC with the app
 * secret, and nothing is written before that checks out.
 */
class FacebookDataDeletionController extends Controller
{
    public function callback(Request $request, DeleteFacebookData $delete): JsonResponse
    {
        abort_unless(SocialProvider::Facebook->isConfigured(), 404);

        $payload = FacebookSignedRequest::parse(
            (string) $request->input('signed_request'),
            (string) config('services.facebook.client_secret'),
        );

        $userId = $payload['user_id'] ?? null;

        if ($payload === null || (! is_string($userId) && ! is_int($userId)) || (string) $userId === '') {
            return new JsonResponse(['error' => 'invalid_signed_request'], 400);
        }

        $deletion = $delete((string) $userId);

        return new JsonResponse([
            'url' => $this->statusUrl($deletion->confirmation_code),
            'confirmation_code' => $deletion->confirmation_code,
        ]);
    }

    /** The instructions page — the "Data deletion instructions URL" in Meta. */
    public function instructions(): Response
    {
        return Inertia::render('Privacy/FacebookDataDeletion', ['deletion' => null]);
    }

    /** The status page the callback's `url` points at. */
    public function status(string $code): Response
    {
        $deletion = SocialDataDeletionRequest::query()
            ->where('confirmation_code', strtoupper($code))
            ->firstOrFail();

        return Inertia::render('Privacy/FacebookDataDeletion', [
            'deletion' => [
                'code' => $deletion->confirmation_code,
                'completed_at' => $deletion->completed_at?->toIso8601String(),
                'deleted_accounts' => $deletion->deleted_accounts,
                // Pluralised here: the frontend t() helper has no plural rules.
                'summary' => trans_choice('app.facebook_data_deletion.status_deleted', $deletion->deleted_accounts, [
                    'count' => $deletion->deleted_accounts,
                ]),
            ],
        ]);
    }

    private function statusUrl(string $code): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return sprintf('%s://%s/facebook/data-deletion/%s', $scheme, config('tenancy.central_domain'), $code);
    }
}
