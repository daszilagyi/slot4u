<?php

use App\Models\Tenant;
use App\Support\Monitoring\PiiRedactor;
use App\Support\Monitoring\SentryScrubber;
use App\Tenancy\TenantManager;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\UserDataBag;

/*
|--------------------------------------------------------------------------
| Sentry PII scrubbing (SLO-153)
|--------------------------------------------------------------------------
|
| The AC is "a production error shows up in Sentry, without customer data".
| These are the tests that make the second half of that sentence true: every
| path an email, a phone number or a request body could take out of the process.
|
*/

function scrub(Event $event): Event
{
    return (new SentryScrubber)->scrub($event);
}

it('redacts contact details from an exception message', function () {
    $event = Event::createEvent();
    $event->setExceptions([
        new ExceptionDataBag(new RuntimeException('Failed to mail jane.doe@example.com about booking +36301234567')),
    ]);

    $value = scrub($event)->getExceptions()[0]->getValue();

    expect($value)
        ->toContain(PiiRedactor::EMAIL)
        ->toContain(PiiRedactor::PHONE)
        ->not->toContain('jane.doe@example.com')
        ->not->toContain('36301234567')
        // The sentence around the data is what makes the error diagnosable.
        ->toContain('Failed to mail');
});

it('drops the request body, query string, cookies and headers', function () {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://acme.slot4u.hu/book',
        'method' => 'POST',
        'query_string' => 'customer_search=jane%40example.com',
        'cookies' => ['slot4u_session' => 'secret-session-id'],
        'headers' => ['authorization' => 'Bearer secret'],
        'data' => ['guest_name' => 'Jane Doe', 'guest_email' => 'jane@example.com'],
        'env' => ['REMOTE_ADDR' => '203.0.113.9'],
    ]);

    $request = scrub($event)->getRequest();

    expect(array_keys($request))->toBe(['url', 'method']);
    expect($request['url'])->toBe('https://acme.slot4u.hu/book');
    // Not "the listed keys were removed" — everything unlisted is gone, so a
    // future SDK field cannot ship customer data by being new.
    expect(json_encode($request))
        ->not->toContain('Jane Doe')
        ->not->toContain('secret-session-id')
        ->not->toContain('Bearer');
});

it('redacts a search term carried in the url', function () {
    $event = Event::createEvent();
    $event->setRequest(['url' => 'https://acme.slot4u.hu/customers?search=jane@example.com', 'method' => 'GET']);

    expect(scrub($event)->getRequest()['url'])
        ->toContain(PiiRedactor::EMAIL)
        ->not->toContain('jane@example.com');
});

it('reduces the user to an id', function () {
    $user = UserDataBag::createFromArray([
        'id' => 42,
        'email' => 'jane@example.com',
        'username' => 'Jane Doe',
        'ip_address' => '203.0.113.9',
    ]);

    $event = Event::createEvent();
    $event->setUser($user);

    $scrubbed = scrub($event)->getUser();

    expect($scrubbed?->getId())->toBe(42)
        ->and($scrubbed?->getEmail())->toBeNull()
        ->and($scrubbed?->getUsername())->toBeNull()
        ->and($scrubbed?->getIpAddress())->toBeNull();
});

it('drops a user context that carries no id at all', function () {
    // An IP-only user bag identifies a person and nothing else useful.
    $event = Event::createEvent();
    $event->setUser(UserDataBag::createFromUserIpAddress('203.0.113.9'));

    expect(scrub($event)->getUser())->toBeNull();
});

it('redacts breadcrumb messages and metadata', function () {
    $breadcrumb = new Breadcrumb(
        Breadcrumb::LEVEL_INFO,
        Breadcrumb::TYPE_DEFAULT,
        'log',
        'Confirmation sent to jane@example.com',
        ['recipient' => 'jane@example.com', 'nested' => ['phone' => '+36 30 123 4567']],
    );

    $scrubbed = (new SentryScrubber)->scrubBreadcrumb($breadcrumb);

    expect($scrubbed->getMessage())->toContain(PiiRedactor::EMAIL)->not->toContain('jane@');
    expect($scrubbed->getMetadata()['recipient'])->toBe(PiiRedactor::EMAIL);
    expect($scrubbed->getMetadata()['nested']['phone'])->toBe(PiiRedactor::PHONE);
});

it('redacts extra data and contexts nested to any depth', function () {
    $event = Event::createEvent();
    $event->setExtra(['payload' => ['contact' => ['email' => 'jane@example.com']]]);
    $event->setContext('booking', ['guest' => '+36301234567']);

    $scrubbed = scrub($event);

    expect($scrubbed->getExtra()['payload']['contact']['email'])->toBe(PiiRedactor::EMAIL);
    expect($scrubbed->getContexts()['booking']['guest'])->toBe(PiiRedactor::PHONE);
});

it('tags the event with the tenant being served', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->set($tenant);

    $tags = scrub(Event::createEvent())->getTags();

    expect($tags['tenant'])->toBe('acme')
        ->and($tags['tenant_id'])->toBe((string) $tenant->id);
});

it('reports the error even when no tenant is bound', function () {
    // The central domain, a console command, a crash before tenant resolution.
    app(TenantManager::class)->forget();

    expect(scrub(Event::createEvent())->getTags())->not->toHaveKey('tenant');
});

it('leaves text without contact details untouched', function () {
    // A scrubber that mangled ordinary messages would cost more than it saves.
    $message = 'SQLSTATE[42S02]: Base table or view not found: bookings';

    expect(PiiRedactor::text($message))->toBe($message);
});

it('does not mistake an id or a price for a phone number', function () {
    expect(PiiRedactor::text('Booking 12345678 costs 45000 HUF at 2026-08-09 10:30:00'))
        ->toBe('Booking 12345678 costs 45000 HUF at 2026-08-09 10:30:00');
});
