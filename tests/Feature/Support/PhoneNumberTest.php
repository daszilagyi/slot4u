<?php

use App\Models\Tenant;
use App\Support\PhoneNumber;

/**
 * The one place phone numbers are parsed (SLO-151). Validation and normalization
 * are the same call, so these cases describe both at once.
 */
it('turns a number into E.164', function (string $input, string $expected) {
    expect(PhoneNumber::toE164($input, 'HU'))->toBe($expected);
})->with([
    'already canonical' => ['+36301234567', '+36301234567'],
    'spaced international' => ['+36 30 123 4567', '+36301234567'],
    'national trunk prefix' => ['06301234567', '+36301234567'],
    'punctuated' => ['06-30/123-4567', '+36301234567'],
    'bare subscriber number' => ['30 123 4567', '+36301234567'],
    'landline' => ['+36 1 234 5678', '+3612345678'],
    'surrounding whitespace' => ['  +36301234567  ', '+36301234567'],
    // The region supplies the international dialling prefix to strip, so a
    // Hungarian dialling Austria the way they would from a phone still works.
    'international dialling prefix' => ['00436641234567', '+436641234567'],
    'foreign, with country code' => ['+43 664 1234567', '+436641234567'],
    'far foreign' => ['+1 202 555 0143', '+12025550143'],
]);

it('refuses anything that is not a real number', function (string $input) {
    expect(PhoneNumber::toE164($input, 'HU'))->toBeNull();
})->with([
    // The exact value Daniel found in a production booking.
    'letters' => ['sfsdfsdfsd'],
    'markup' => ['<script>alert(1)</script>'],
    'too short' => ['1234'],
    'a single zero' => ['0'],
    'impossible country code' => ['+999999999999'],
    'too long for its country' => ['+36 30 123 456789012345'],
    // Austrian national format on a Hungarian tenant: indistinguishable from a
    // malformed domestic number, so it is refused rather than guessed at.
    'foreign national format' => ['0664 1234567'],
    'empty' => [''],
]);

it('treats a blank optional field as absent rather than as bad input', function (?string $input) {
    expect(PhoneNumber::normalizeInput($input, 'HU'))->toBeNull();
})->with([[null], [''], ['   ']]);

it('hands back what was typed when it cannot be parsed, for the rule to report', function () {
    expect(PhoneNumber::normalizeInput('  sfsdfsdfsd  ', 'HU'))->toBe('sfsdfsdfsd');
});

it('reads the dialling region off the timezone', function (?string $timezone, string $expected) {
    expect(PhoneNumber::regionForTimezone($timezone))->toBe($expected);
})->with([
    'Hungary' => ['Europe/Budapest', 'HU'],
    'Austria' => ['Europe/Vienna', 'AT'],
    'Germany' => ['Europe/Berlin', 'DE'],
    // UTC belongs to no country; so does a name the tz database does not know.
    'no country' => ['UTC', 'HU'],
    'unknown zone' => ['Not/AZone', 'HU'],
    'no tenant' => [null, 'HU'],
]);

it('honours the configured platform default when the timezone names no country', function () {
    config(['tenancy.default_phone_region' => 'AT']);

    expect(PhoneNumber::regionForTimezone('UTC'))->toBe('AT')
        // A tenant that does name a country still wins over the default.
        ->and(PhoneNumber::regionForTimezone('Europe/Budapest'))->toBe('HU');
});

it('falls back to Hungary when the configured default is nonsense', function () {
    config(['tenancy.default_phone_region' => 'not-a-country']);

    expect(PhoneNumber::regionForTimezone('UTC'))->toBe(PhoneNumber::DEFAULT_REGION);
});

it('takes the region from the tenant', function () {
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Vienna']);

    expect(PhoneNumber::regionFor($tenant))->toBe('AT');
});

it('shows an example in the visitor own country format', function () {
    expect(PhoneNumber::example('HU'))->toStartWith('+36 ')
        ->and(PhoneNumber::example('AT'))->toStartWith('+43 ')
        // An unknown region still produces something to show.
        ->and(PhoneNumber::example('XX'))->toStartWith('+36 ');
});
