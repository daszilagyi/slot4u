<?php

use App\Enums\BookingMode;
use App\Enums\Feature;
use App\Enums\QuoteRequestStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestMessage;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantFeature;
use Database\Seeders\BasePlanSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// tenantHost() lives in tests/Pest.php.

beforeEach(function () {
    // Seeds plan_features → feature_quote_request is on for the base plan.
    $this->seed(BasePlanSeeder::class);
});

/**
 * A quote_request service of the `acme` tenant, asking two questions.
 *
 * @param  list<string>  $fields
 * @return array{0: Tenant, 1: Service}
 */
function publicQuoteService(array $fields = ['Létszám', 'Helyszín'], array $overrides = []): array
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $service = Service::factory()->forTenant($tenant)->mode(BookingMode::QuoteRequest)->create(array_merge([
        'active' => true,
        'duration_minutes' => null,
        'requires_staff' => false,
        'settings' => ['quote_fields' => $fields],
    ], $overrides));

    return [$tenant, $service];
}

/**
 * @return array<string, mixed>
 */
function quoteGuest(array $overrides = []): array
{
    return array_merge([
        'name' => 'Kovács Anna',
        'email' => 'anna@example.test',
        'phone' => '+36301234567',
        'notes' => 'Szeretnék egy előzetes árat.',
        'fields' => ['40 fő', 'Budapest'],
    ], $overrides);
}

it('renders the service-defined quote form', function () {
    [, $service] = publicQuoteService();

    $this->get(tenantHost('acme', '/book?service='.$service->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Book')
            ->where('service.booking_mode', 'quote_request')
            ->where('quote_enabled', true)
            ->where('quote_fields', ['Létszám', 'Helyszín'])
            // No slot / event machinery is computed for this mode.
            ->missing('slots')
            ->missing('events'));
});

it('marks the form unavailable when the tenant feature is off', function () {
    [$tenant, $service] = publicQuoteService();
    TenantFeature::factory()->create([
        'tenant_id' => $tenant->id,
        'feature_code' => Feature::QuoteRequest,
        'enabled' => false,
    ]);

    $this->get(tenantHost('acme', '/book?service='.$service->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('quote_enabled', false));
});

it('creates a new quote request with the answers keyed by the service labels', function () {
    [$tenant, $service] = publicQuoteService();

    $this->post(tenantHost('acme', '/quote'), quoteGuest(['service_id' => $service->id]))
        ->assertRedirect('/quote-sent');

    $quoteRequest = QuoteRequest::query()->firstOrFail();
    $customer = Customer::query()->where('email', 'anna@example.test')->firstOrFail();

    expect($quoteRequest->status)->toBe(QuoteRequestStatus::New)
        ->and($quoteRequest->tenant_id)->toBe($tenant->id)
        ->and($quoteRequest->service_id)->toBe($service->id)
        ->and($quoteRequest->customer_id)->toBe($customer->id)
        ->and($quoteRequest->parameters)->toBe(['Létszám' => '40 fő', 'Helyszín' => 'Budapest'])
        // The mode never books, and the admin's working notes stay admin-only.
        ->and($quoteRequest->booking_id)->toBeNull()
        ->and($quoteRequest->internal_notes)->toBeNull()
        ->and(Booking::query()->count())->toBe(0);
});

it('files the guest message as the first message of the conversation', function () {
    [, $service] = publicQuoteService();

    $this->post(tenantHost('acme', '/quote'), quoteGuest(['service_id' => $service->id]));

    $message = QuoteRequestMessage::query()->firstOrFail();
    $customer = Customer::query()->where('email', 'anna@example.test')->firstOrFail();

    expect($message->body)->toBe('Szeretnék egy előzetes árat.')
        ->and($message->user_id)->toBe($customer->id)
        ->and($message->quote_request_id)->toBe(QuoteRequest::query()->firstOrFail()->id);
});

it('posts no message when the guest leaves it empty', function () {
    [, $service] = publicQuoteService();

    $this->post(tenantHost('acme', '/quote'), quoteGuest(['service_id' => $service->id, 'notes' => '   ']))
        ->assertRedirect('/quote-sent');

    expect(QuoteRequestMessage::query()->count())->toBe(0);
});

it('accepts a service that asks no questions', function () {
    [, $service] = publicQuoteService([]);

    $this->post(tenantHost('acme', '/quote'), quoteGuest(['service_id' => $service->id, 'fields' => []]))
        ->assertRedirect('/quote-sent');

    expect(QuoteRequest::query()->firstOrFail()->parameters)->toBeNull();
});

it('shows the confirmation page after the redirect', function () {
    [, $service] = publicQuoteService();

    $this->post(tenantHost('acme', '/quote'), quoteGuest(['service_id' => $service->id]));

    $this->get(tenantHost('acme', '/quote-sent'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/QuoteSent')
            ->where('quote.service', $service->name));
});

it('sends a direct visit to the confirmation page home', function () {
    publicQuoteService();

    $this->get(tenantHost('acme', '/quote-sent'))->assertRedirect('/');
});

it('403s the submission when the tenant feature is off', function () {
    [$tenant, $service] = publicQuoteService();
    TenantFeature::factory()->create([
        'tenant_id' => $tenant->id,
        'feature_code' => Feature::QuoteRequest,
        'enabled' => false,
    ]);

    $this->post(tenantHost('acme', '/quote'), quoteGuest(['service_id' => $service->id]))
        ->assertForbidden();

    expect(QuoteRequest::query()->count())->toBe(0);
});

it('404s when the service is not a quote_request service', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $service = Service::factory()->forTenant($tenant)->create(['active' => true]);

    $this->post(tenantHost('acme', '/quote'), quoteGuest(['service_id' => $service->id, 'fields' => []]))
        ->assertNotFound();

    expect(QuoteRequest::query()->count())->toBe(0);
});

it('rejects answers that do not line up with the service form', function () {
    [, $service] = publicQuoteService();

    // One answer for a two-question form: the page was rendered against another
    // field set, or the payload was crafted by hand.
    $this->post(tenantHost('acme', '/quote'), quoteGuest(['service_id' => $service->id, 'fields' => ['40 fő']]))
        ->assertSessionHasErrors('quote');

    expect(QuoteRequest::query()->count())->toBe(0);
});

it('never lets the payload choose the parameters keys', function () {
    [, $service] = publicQuoteService();

    // Caller-chosen keys are only ever read positionally, so they cannot write
    // arbitrary json into `parameters`.
    $this->post(tenantHost('acme', '/quote'), quoteGuest([
        'service_id' => $service->id,
        'fields' => ['internal_notes' => '40 fő', 'price_minor' => 'Budapest'],
    ]))->assertRedirect('/quote-sent');

    expect(QuoteRequest::query()->firstOrFail()->parameters)
        ->toBe(['Létszám' => '40 fő', 'Helyszín' => 'Budapest']);
});

it('requires every answer of the service form', function () {
    [, $service] = publicQuoteService();

    $this->post(tenantHost('acme', '/quote'), quoteGuest(['service_id' => $service->id, 'fields' => ['', 'Budapest']]))
        ->assertSessionHasErrors('fields.0');

    expect(QuoteRequest::query()->count())->toBe(0);
});

it('validates the guest contact details', function () {
    [, $service] = publicQuoteService();

    $this->post(tenantHost('acme', '/quote'), ['service_id' => $service->id, 'email' => 'not-an-email', 'fields' => ['a', 'b']])
        ->assertSessionHasErrors(['name', 'email']);

    expect(QuoteRequest::query()->count())->toBe(0);
});

it('refuses a quote request for an inactive service', function () {
    [, $service] = publicQuoteService(['Létszám', 'Helyszín'], ['active' => false]);

    $this->post(tenantHost('acme', '/quote'), quoteGuest(['service_id' => $service->id]))
        ->assertSessionHasErrors('service_id');

    expect(QuoteRequest::query()->count())->toBe(0);
});

it('refuses a quote request for another tenant\'s service', function () {
    publicQuoteService();
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $foreign = Service::factory()->forTenant($other)->mode(BookingMode::QuoteRequest)->create([
        'active' => true,
        'settings' => ['quote_fields' => ['Létszám', 'Helyszín']],
    ]);

    $this->post(tenantHost('acme', '/quote'), quoteGuest(['service_id' => $foreign->id]))
        ->assertSessionHasErrors('service_id');

    expect(QuoteRequest::query()->withoutGlobalScopes()->count())->toBe(0);
});
