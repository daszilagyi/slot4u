<?php

namespace App\Enums;

/**
 * Tenant feature flags (docs/03). The default value comes from the plan
 * (`plan_features`); superadmin may override per tenant (`tenant_features`).
 */
enum Feature: string
{
    case OnlinePayment = 'feature_online_payment';
    case Invoicing = 'feature_invoicing';
    case CustomDomain = 'feature_custom_domain';
    case Branding = 'feature_branding';
    case Waitlist = 'feature_waitlist';
    case QuoteRequest = 'feature_quote_request';
    case ApprovalFlow = 'feature_approval_flow';
    case Messages = 'feature_messages';
    case Documents = 'feature_documents';
    case Reports = 'feature_reports';
    case Sms = 'feature_sms';
    case Api = 'feature_api';
    case NlpBooking = 'feature_nlp_booking';
    case GoogleMeet = 'feature_google_meet';
    case Analytics = 'feature_analytics';

    /**
     * Rate-raising integrations: free to enable, but bump the tenant's commission
     * rate when active at the moment a booking becomes billable (docs/10 §2.4).
     */
    public function raisesCommissionRate(): bool
    {
        return match ($this) {
            self::OnlinePayment, self::Invoicing => true,
            default => false,
        };
    }

    /**
     * Whether the free `base` plan grants this feature by default (docs/10 §5.6).
     *
     * Rate-raising integrations are opt-in (enabling them raises the rate), and
     * external-cost / later features stay off until explicitly enabled. The
     * remaining core operational features are free on the base plan.
     */
    public function enabledByDefaultOnBase(): bool
    {
        return match ($this) {
            self::OnlinePayment,
            self::Invoicing,
            self::CustomDomain,
            // Branding (public-page customisation) is off by default so the
            // settings UI shows a locked section with an enable CTA (SLO-21 AC);
            // a superadmin turns it on per tenant via tenant_features.
            self::Branding,
            self::Sms,
            self::Api,
            self::NlpBooking,
            self::GoogleMeet => false,
            // Analytics (SLO-56) falls through to `true` deliberately. It costs
            // slot4u nothing — the tenant measures into its OWN GA4 property and
            // Meta pixel — and under a commission model a tenant that can see its
            // own funnel and grow its traffic is the platform's interest too.
            // Locking it behind a superadmin switch would have been a toll on
            // something with no toll to collect.
            default => true,
        };
    }
}
