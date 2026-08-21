<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a consent was given (SLO-161).
 *
 * Kept because art. 7(1) asks the controller to demonstrate *that* the subject
 * consented, and "on which screen" is a large part of what makes that credible
 * a year later — a tick box on a booking form and a blocking re-acceptance
 * screen are different acts, and only the record tells them apart.
 */
enum ConsentContext: string
{
    /** A company signing up for slot4u on the central domain. */
    case TenantRegistration = 'tenant_registration';

    /** A customer creating an account on a tenant's subdomain. */
    case CustomerRegistration = 'customer_registration';

    /** A public booking — with or without an account. */
    case Booking = 'booking';

    /** A public order for a service that has no time slot (docs/04 §1). */
    case Order = 'order';

    /** A public sign-up for an event. */
    case EventBooking = 'event_booking';

    /** A public quote request (docs/04 §6). */
    case QuoteRequest = 'quote_request';

    /** Re-acceptance forced by a new version of a document already accepted. */
    case Reconsent = 'reconsent';
}
