<?php

use App\Enums\Role;
use App\Models\SocialAccount;
use App\Models\SocialDataDeletionRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SocialAuth\FacebookSignedRequest;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
 * Meta's user data deletion callback (SLO-253, docs/28 §6): a signed_request
 * verified with the app secret deletes the Facebook links and the Facebook
 * profile stored with them — never the account, never another provider's link —
 * and answers with a status URL and a confirmation code.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);

    config([
        'services.facebook.client_id' => 'facebook-id',
        'services.facebook.client_secret' => 'facebook-secret',
    ]);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** A signed_request exactly as Meta builds one. */
function fbSignedRequest(array $payload, string $secret = 'facebook-secret'): string
{
    $encode = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    $encodedPayload = $encode((string) json_encode($payload));
    $signature = $encode(hash_hmac('sha256', $encodedPayload, $secret, true));

    return $signature.'.'.$encodedPayload;
}

function fbDeletionUrl(string $path = '/auth/facebook/data-deletion'): string
{
    return 'http://'.config('tenancy.central_domain').$path;
}

it('deletes the Facebook links of that Facebook user and answers with a status url and code', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $anna = socialStaff($tenant, Role::Customer->value);
    $annaElsewhere = socialStaff($other, Role::Customer->value);

    SocialAccount::factory()->linkedTo($anna)->facebook()->create(['provider_user_id' => 'fb-anna']);
    SocialAccount::factory()->linkedTo($annaElsewhere)->facebook()->create(['provider_user_id' => 'fb-anna-2']);
    $google = SocialAccount::factory()->linkedTo($anna)->create(['provider_user_id' => 'g-anna']);
    $bystander = SocialAccount::factory()->linkedTo(socialStaff($other, Role::Customer->value))->facebook()->create(['provider_user_id' => 'fb-someone']);
    // The same id string at ANOTHER provider is a different person's identity.
    SocialAccount::factory()->linkedTo(socialStaff($other, Role::Customer->value))->create(['provider_user_id' => 'fb-anna']);

    $response = $this->postJson(fbDeletionUrl(), [
        'signed_request' => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => 'fb-anna', 'issued_at' => time()]),
    ])->assertOk();

    $code = $response->json('confirmation_code');

    expect($code)->toMatch('/^[A-Z0-9]{20}$/')
        ->and($response->json('url'))->toBe(fbDeletionUrl('/facebook/data-deletion/'.$code));

    $remaining = SocialAccount::query()->withoutGlobalScopes()->pluck('provider_user_id')->sort()->values()->all();
    expect($remaining)->toBe(['fb-anna', 'fb-anna-2', 'fb-someone', 'g-anna']);

    // The account and the other provider's link stay (docs/19 §1).
    expect(User::query()->find($anna->id))->not->toBeNull()
        ->and($google->fresh())->not->toBeNull()
        ->and($bystander->fresh())->not->toBeNull();

    $request = SocialDataDeletionRequest::query()->sole();
    expect($request->deleted_accounts)->toBe(1)
        ->and($request->provider_user_hash)->toBe(hash('sha256', 'fb-anna'))
        ->and($request->completed_at)->not->toBeNull();
});

it('answers an id it holds nothing for with a code too', function () {
    $this->postJson(fbDeletionUrl(), [
        'signed_request' => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '404']),
    ])->assertOk()->assertJsonStructure(['url', 'confirmation_code']);

    expect(SocialDataDeletionRequest::query()->sole()->deleted_accounts)->toBe(0);
});

it('refuses a request not signed with the app secret, and deletes nothing', function (string $signedRequest) {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    SocialAccount::factory()->linkedTo(socialStaff($tenant, Role::Customer->value))->facebook()->create(['provider_user_id' => 'fb-anna']);

    $this->postJson(fbDeletionUrl(), ['signed_request' => $signedRequest])
        ->assertStatus(400)
        ->assertJson(['error' => 'invalid_signed_request']);

    expect(SocialAccount::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(SocialDataDeletionRequest::query()->count())->toBe(0);
})->with([
    'wrong secret' => fn () => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => 'fb-anna'], 'guessed-secret'),
    'tampered payload' => fn () => explode('.', fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => 'someone']))[0]
        .'.'.explode('.', fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => 'fb-anna']))[1],
    'other algorithm' => fn () => fbSignedRequest(['algorithm' => 'none', 'user_id' => 'fb-anna']),
    'no user id' => fn () => fbSignedRequest(['algorithm' => 'HMAC-SHA256']),
    'not a signed request' => fn () => 'garbage',
    'empty' => fn () => '',
]);

it('parses only well-formed, correctly signed requests', function () {
    $valid = fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '1']);

    expect(FacebookSignedRequest::parse($valid, 'facebook-secret'))->toMatchArray(['user_id' => '1'])
        ->and(FacebookSignedRequest::parse($valid, ''))->toBeNull()
        ->and(FacebookSignedRequest::parse($valid.'.x', 'facebook-secret'))->toBeNull()
        ->and(FacebookSignedRequest::parse('a*b.c', 'facebook-secret'))->toBeNull();
});

it('404s the callback while Facebook is not configured', function () {
    config(['services.facebook.client_secret' => null]);

    $this->postJson(fbDeletionUrl(), [
        'signed_request' => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '1'], ''),
    ])->assertNotFound();
});

it('exists only on the central domain', function () {
    Tenant::factory()->active()->create(['slug' => 'acme']);

    $this->postJson('http://acme.'.config('tenancy.central_domain').'/auth/facebook/data-deletion', [
        'signed_request' => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '1']),
    ])->assertNotFound();
});

it('is exempt from CSRF, since Meta has no session to carry a token', function () {
    // `validateCsrfTokens(except:)` lands in the middleware's static list, which
    // the unit-test bypass would otherwise leave untested.
    $except = (fn () => $this->getExcludedPaths())->call(app(ValidateCsrfToken::class));

    expect($except)->toContain('auth/facebook/data-deletion');
});

it('shows the status page for a confirmation code', function () {
    $this->postJson(fbDeletionUrl(), [
        'signed_request' => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => 'fb-anna']),
    ]);
    $code = SocialDataDeletionRequest::query()->sole()->confirmation_code;

    $this->get(fbDeletionUrl('/facebook/data-deletion/'.$code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Privacy/FacebookDataDeletion')
            ->where('deletion.code', $code)
            ->where('deletion.deleted_accounts', 0)
            ->where('deletion.summary', 'Nem tároltunk Facebook-adatot ehhez a fiókhoz.'));

    $this->get(fbDeletionUrl('/facebook/data-deletion/'.str_repeat('Z', 20)))->assertNotFound();
});

it('shows the instructions page without a code', function () {
    $this->get(fbDeletionUrl('/facebook/data-deletion'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Privacy/FacebookDataDeletion')
            ->where('deletion', null));
});
