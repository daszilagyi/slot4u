<?php

namespace App\Settings;

use App\Enums\RefundPolicy;

/**
 * Typed view over a tenant's `settings` JSON (SLO-21): company profile fields and
 * the booking rules the M3 engine consumes (online cancellation deadline, slot
 * grid interval). Reading through this class guarantees defaults and a stable
 * shape regardless of what is (or isn't) stored.
 */
final class TenantSettings
{
    public const DEFAULT_CANCELLATION_DEADLINE_HOURS = 24;

    public const DEFAULT_SLOT_INTERVAL_MINUTES = 30;

    /** How long a waitlist offer stays open before rolling to the next waiter. */
    public const DEFAULT_WAITLIST_OFFER_HOURS = 24;

    /** How long a requested (approval-pending) booking soft-holds its slot. */
    public const DEFAULT_APPROVAL_HOLD_HOURS = 48;

    /**
     * How long a pending_payment booking holds its slot before the expiry sweep
     * releases it (docs/04, SLO-130). Minutes, not hours: an unpaid checkout must
     * hand the slot back quickly. Falls back to the platform default in
     * `config/payments.php`.
     */
    public const DEFAULT_PAYMENT_HOLD_MINUTES = 30;

    /** Allowed slot grid intervals in minutes (docs/02 booking rules). */
    public const SLOT_INTERVALS = [15, 30];

    /**
     * What is refunded automatically when a paid booking is cancelled (SLO-131).
     * Defaults to `none`: handing money back is a business decision the tenant has
     * to opt into, and an admin can always refund by hand.
     */
    public const DEFAULT_REFUND_POLICY = RefundPolicy::None;

    /** The share refunded under the `partial` policy, in basis points (50% = 5000). */
    public const DEFAULT_REFUND_PERCENT_BPS = 5000;

    /** Social platforms exposed on the profile (stable, typed keys). */
    public const SOCIAL_PLATFORMS = ['website', 'facebook', 'instagram'];

    /**
     * @param  array<string, string>  $social  platform => url (only non-empty)
     */
    public function __construct(
        public readonly ?string $description = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $addressLine = null,
        public readonly ?string $addressCity = null,
        public readonly ?string $addressPostal = null,
        public readonly ?string $openingHours = null,
        public readonly array $social = [],
        public readonly int $cancellationDeadlineHours = self::DEFAULT_CANCELLATION_DEADLINE_HOURS,
        public readonly int $slotIntervalMinutes = self::DEFAULT_SLOT_INTERVAL_MINUTES,
        public readonly int $waitlistOfferHours = self::DEFAULT_WAITLIST_OFFER_HOURS,
        public readonly int $approvalHoldHours = self::DEFAULT_APPROVAL_HOLD_HOURS,
        public readonly int $paymentHoldMinutes = self::DEFAULT_PAYMENT_HOLD_MINUTES,
        public readonly RefundPolicy $refundPolicy = self::DEFAULT_REFUND_POLICY,
        public readonly int $refundPercentBps = self::DEFAULT_REFUND_PERCENT_BPS,
    ) {}

    /**
     * What this tenant refunds automatically for a settled payment of
     * `$paidMinor` (SLO-131).
     */
    public function automaticRefundMinor(int $paidMinor): int
    {
        return $this->refundPolicy->amountFor($paidMinor, $this->refundPercentBps);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $social = [];
        foreach (self::SOCIAL_PLATFORMS as $platform) {
            $url = $data['social'][$platform] ?? null;
            if (is_string($url) && $url !== '') {
                $social[$platform] = $url;
            }
        }

        $interval = (int) ($data['slot_interval_minutes'] ?? self::DEFAULT_SLOT_INTERVAL_MINUTES);

        return new self(
            description: self::str($data, 'description'),
            email: self::str($data, 'email'),
            phone: self::str($data, 'phone'),
            addressLine: self::str($data, 'address_line'),
            addressCity: self::str($data, 'address_city'),
            addressPostal: self::str($data, 'address_postal'),
            openingHours: self::str($data, 'opening_hours'),
            social: $social,
            cancellationDeadlineHours: max(0, (int) ($data['cancellation_deadline_hours'] ?? self::DEFAULT_CANCELLATION_DEADLINE_HOURS)),
            slotIntervalMinutes: in_array($interval, self::SLOT_INTERVALS, true) ? $interval : self::DEFAULT_SLOT_INTERVAL_MINUTES,
            waitlistOfferHours: max(1, (int) ($data['waitlist_offer_hours'] ?? self::DEFAULT_WAITLIST_OFFER_HOURS)),
            approvalHoldHours: max(1, (int) ($data['approval_hold_hours'] ?? self::DEFAULT_APPROVAL_HOLD_HOURS)),
            paymentHoldMinutes: max(1, (int) ($data['payment_hold_minutes'] ?? config('payments.hold_minutes', self::DEFAULT_PAYMENT_HOLD_MINUTES))),
            refundPolicy: RefundPolicy::tryFrom((string) ($data['refund_policy'] ?? '')) ?? self::DEFAULT_REFUND_POLICY,
            refundPercentBps: min(10_000, max(0, (int) ($data['refund_percent_bps'] ?? self::DEFAULT_REFUND_PERCENT_BPS))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'email' => $this->email,
            'phone' => $this->phone,
            'address_line' => $this->addressLine,
            'address_city' => $this->addressCity,
            'address_postal' => $this->addressPostal,
            'opening_hours' => $this->openingHours,
            'social' => $this->social,
            'cancellation_deadline_hours' => $this->cancellationDeadlineHours,
            'slot_interval_minutes' => $this->slotIntervalMinutes,
            'waitlist_offer_hours' => $this->waitlistOfferHours,
            'approval_hold_hours' => $this->approvalHoldHours,
            'payment_hold_minutes' => $this->paymentHoldMinutes,
            'refund_policy' => $this->refundPolicy->value,
            'refund_percent_bps' => $this->refundPercentBps,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
