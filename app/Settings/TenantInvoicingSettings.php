<?php

namespace App\Settings;

use App\Enums\InvoiceProvider;

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
        /**
         * Which service issues this tenant's invoices (SLO-167). Null means the
         * tenant has not chosen, and the platform default applies — the config
         * is a fallback, not the decision.
         */
        public readonly ?InvoiceProvider $provider = null,
        public readonly ?string $apiKey = null,
        public readonly ?string $sellerName = null,
        public readonly ?string $sellerTaxNumber = null,
        public readonly ?string $sellerAddress = null,
        public readonly string $vatKey = self::DEFAULT_VAT_KEY,
        /**
         * Billingo requires every document to name a document block, and offers
         * a bank account to print on it. Both are ids from the tenant's OWN
         * Billingo account, so they are picked from a list the settings screen
         * fetches — never typed in (SLO-167).
         */
        public readonly ?int $blockId = null,
        public readonly ?int $bankAccountId = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];

        return new self(
            provider: InvoiceProvider::tryFrom((string) ($data['provider'] ?? '')),
            apiKey: self::str($data, 'api_key'),
            sellerName: self::str($data, 'seller_name'),
            sellerTaxNumber: self::str($data, 'seller_tax_number'),
            sellerAddress: self::str($data, 'seller_address'),
            vatKey: self::str($data, 'vat_key') ?? self::DEFAULT_VAT_KEY,
            blockId: self::int($data, 'block_id'),
            bankAccountId: self::int($data, 'bank_account_id'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider?->value,
            'api_key' => $this->apiKey,
            'seller_name' => $this->sellerName,
            'seller_tax_number' => $this->sellerTaxNumber,
            'seller_address' => $this->sellerAddress,
            'vat_key' => $this->vatKey,
            'block_id' => $this->blockId,
            'bank_account_id' => $this->bankAccountId,
        ];
    }

    /** Whether a provider credential is configured (never the key itself). */
    public function hasApiKey(): bool
    {
        return $this->apiKey !== null;
    }

    /**
     * Whether this tenant can actually have an invoice issued for it.
     *
     * Checked before anything is recorded, so a half-configured tenant does not
     * collect `failed` invoice rows for a credential nobody ever entered.
     */
    public function isComplete(): bool
    {
        if ($this->provider === null || ! $this->hasApiKey()) {
            return false;
        }

        return $this->provider !== InvoiceProvider::Billingo || $this->blockId !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
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
