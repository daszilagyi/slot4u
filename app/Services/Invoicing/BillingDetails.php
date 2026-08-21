<?php

declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Models\Booking;

/**
 * The buyer's billing details for one transaction (SLO-168).
 *
 * A value object rather than six parameters threaded through the invoicing
 * layer: the interesting question is not what the fields are but whether they
 * are *complete enough to invoice with*, and that question deserves one place to
 * live.
 *
 * "Complete" means what the Áfa tv. 169. § e) requires — a name and a full
 * address. An answer of "no" is not an error here: it is the ordinary case, and
 * it means a receipt is issued instead.
 */
final class BillingDetails
{
    public function __construct(
        public readonly bool $wantsInvoice = false,
        public readonly ?string $name = null,
        public readonly ?string $taxNumber = null,
        public readonly ?string $countryCode = null,
        public readonly ?string $postCode = null,
        public readonly ?string $city = null,
        public readonly ?string $address = null,
    ) {}

    public static function fromBooking(Booking $booking): self
    {
        return new self(
            wantsInvoice: (bool) $booking->wants_invoice,
            name: self::clean($booking->billing_name),
            taxNumber: self::clean($booking->billing_tax_number),
            countryCode: self::clean($booking->billing_country_code),
            postCode: self::clean($booking->billing_post_code),
            city: self::clean($booking->billing_city),
            address: self::clean($booking->billing_address),
        );
    }

    /**
     * Whether a full invoice can legally be issued from this.
     *
     * The country code is excluded from the check and defaulted instead: a
     * missing country is an omission with an obvious answer, unlike a missing
     * street, and refusing an otherwise complete address over it would fail an
     * invoice the customer asked for and paid for.
     */
    public function canInvoice(): bool
    {
        return $this->wantsInvoice
            && $this->name !== null
            && $this->postCode !== null
            && $this->city !== null
            && $this->address !== null;
    }

    /** Defaults to Hungary — the only market this MVP serves (docs/01 §7). */
    public function country(): string
    {
        return $this->countryCode ?? 'HU';
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
