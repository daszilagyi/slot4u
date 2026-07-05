<?php

namespace App\Settings;

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

    /** Allowed slot grid intervals in minutes (docs/02 booking rules). */
    public const SLOT_INTERVALS = [15, 30];

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
    ) {}

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
