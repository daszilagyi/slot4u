<?php

namespace App\Settings;

/**
 * Typed view over a tenant's `invoicing` column (SLO-133): who the invoice is
 * issued by, and the credential the provider is called with.
 *
 * Lives apart from {@see TenantSettings} because it holds a SECRET: the column is
 * encrypted at rest, and `apiKey` must never reach an Inertia prop — the settings
 * UI only ever learns whether a key is set ({@see hasApiKey()}).
 */
final class TenantInvoicingSettings
{
    /** Default Hungarian VAT key used when the tenant sets none. */
    public const DEFAULT_VAT_KEY = '27';

    public function __construct(
        public readonly ?string $apiKey = null,
        public readonly ?string $sellerName = null,
        public readonly ?string $sellerTaxNumber = null,
        public readonly ?string $sellerAddress = null,
        public readonly string $vatKey = self::DEFAULT_VAT_KEY,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];

        return new self(
            apiKey: self::str($data, 'api_key'),
            sellerName: self::str($data, 'seller_name'),
            sellerTaxNumber: self::str($data, 'seller_tax_number'),
            sellerAddress: self::str($data, 'seller_address'),
            vatKey: self::str($data, 'vat_key') ?? self::DEFAULT_VAT_KEY,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'api_key' => $this->apiKey,
            'seller_name' => $this->sellerName,
            'seller_tax_number' => $this->sellerTaxNumber,
            'seller_address' => $this->sellerAddress,
            'vat_key' => $this->vatKey,
        ];
    }

    /** Whether a provider credential is configured (never the key itself). */
    public function hasApiKey(): bool
    {
        return $this->apiKey !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
