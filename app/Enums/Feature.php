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
            self::Sms,
            self::Api,
            self::NlpBooking,
            self::GoogleMeet => false,
            default => true,
        };
    }
}
