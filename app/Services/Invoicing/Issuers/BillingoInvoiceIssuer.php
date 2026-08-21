<?php

declare(strict_types=1);

namespace App\Services\Invoicing\Issuers;

use App\Enums\InvoiceProvider;
use App\Models\Invoice;
use App\Models\InvoicingPartner;
use App\Services\Invoicing\Billingo\BillingoClient;
use App\Services\Invoicing\Contracts\InvoiceIssuer;
use App\Services\Invoicing\InvoiceRequest;
use App\Services\Invoicing\IssuedInvoice;
use App\Settings\TenantInvoicingSettings;
use RuntimeException;

/**
 * Issues and voids invoices through Billingo (SLO-167).
 *
 * Everything here was checked against the live API before it was written, and
 * three of its decisions come from that rather than from the specification:
 *
 * 1. ⚠️ **Billingo counts in whole currency units, we count in minor ones.** Our
 *    `amount_minor` is fillér (docs/01 §6); `unit_price` is forint. Sending the
 *    minor value would invoice a HUNDRED TIMES the amount, and no test that
 *    mirrors the same mistake in its fake would notice — hence
 *    {@see majorUnits()} and a test that pins the arithmetic on its own.
 * 2. **A document must name a partner; there is no inline buyer.** So issuing is
 *    two calls, and the partner is remembered on our side because Billingo
 *    cannot look one up by email (see {@see InvoicingPartner}).
 * 3. **Voiding creates a NEW document** with its own number and a negative
 *    total. That is what comes back as the storno.
 * 4. ⚠️ **A document REQUIRES a full buyer address** — which slot4u had nowhere,
 *    and which the Áfa tv. 169. § e) makes mandatory on an invoice regardless of
 *    provider. Hence the two paths below: a receipt by default (no address, no
 *    partner, legally sufficient for a private individual paying by card), and a
 *    full invoice only when the buyer asked for one and supplied an address
 *    (SLO-168).
 */
final class BillingoInvoiceIssuer implements InvoiceIssuer
{
    /** Billingo spells VAT rates as strings: "27%", "AAM", "TAM". */
    private const VAT_SUFFIX = '%';

    public function provider(): InvoiceProvider
    {
        return InvoiceProvider::Billingo;
    }

    public function issue(InvoiceRequest $request): IssuedInvoice
    {
        $client = $this->client($request->seller);

        // A receipt unless an invoice was both asked for and made possible. The
        // check is on the details, not on the request alone: someone who ticked
        // the box but left the address half-filled gets a receipt rather than a
        // failed transaction, and the admin can see what they asked for on the
        // booking.
        return $request->billing->canInvoice()
            ? $this->issueInvoice($client, $request)
            : $this->issueReceipt($client, $request);
    }

    /**
     * A `nyugta`: no partner, no address, its own numbering block.
     */
    private function issueReceipt(BillingoClient $client, InvoiceRequest $request): IssuedInvoice
    {
        $seller = $request->seller;

        if ($seller->receiptBlockId === null) {
            throw new RuntimeException('No Billingo receipt block is configured for this tenant.');
        }

        $receipt = $client->createReceipt(array_filter([
            'block_id' => $seller->receiptBlockId,
            'type' => 'receipt',
            'name' => $request->buyerName,
            'emails' => $request->buyerEmail === null ? null : [$request->buyerEmail],
            'payment_method' => 'online_bankcard',
            'currency' => $request->currency,
            'electronic' => true,
            'items' => [$this->item($request)],
        ], static fn ($value): bool => $value !== null));

        return $this->issued($client, $receipt);
    }

    private function issueInvoice(BillingoClient $client, InvoiceRequest $request): IssuedInvoice
    {
        $seller = $request->seller;

        if ($seller->blockId === null) {
            throw new RuntimeException('No Billingo document block is configured for this tenant.');
        }

        $partnerId = $this->partnerId($client, $request);

        $document = $client->createDocument(array_filter([
            'partner_id' => $partnerId,
            'block_id' => $seller->blockId,
            'bank_account_id' => $seller->bankAccountId,
            'type' => 'invoice',
            'fulfillment_date' => $request->issueDate,
            'due_date' => $request->issueDate,
            // The invoice follows a settled payment, so it is paid on arrival —
            // recording it as unpaid would put the tenant's own Billingo account
            // into a dunning state for money it already has.
            'payment_method' => 'online_bankcard',
            'paid' => true,
            'electronic' => true,
            'language' => 'hu',
            'currency' => $request->currency,
            'items' => [$this->item($request)],
        ], static fn ($value): bool => $value !== null));

        return $this->issued($client, $document);
    }

    /**
     * The single line every document carries.
     *
     * @return array<string, mixed>
     */
    private function item(InvoiceRequest $request): array
    {
        return [
            'name' => $request->itemName,
            'unit_price' => $this->majorUnits($request->amountMinor),
            // Our stored price is what the customer paid — gross. Declaring it
            // as net would silently add VAT on top of VAT.
            'unit_price_type' => 'gross',
            'quantity' => 1,
            'unit' => 'db',
            'vat' => $this->vat($request->seller),
        ];
    }

    public function storno(Invoice $invoice, TenantInvoicingSettings $seller): IssuedInvoice
    {
        $client = $this->client($seller);

        $reference = $invoice->provider_ref;

        if (! is_numeric($reference)) {
            throw new RuntimeException('The invoice carries no Billingo document id to void.');
        }

        return $this->issued($client, $client->cancelDocument((int) $reference));
    }

    /**
     * The buyer's partner id: the one we recorded, or a fresh one.
     *
     * The mapping is written before the document is created, so a failure in
     * between leaves a reusable partner rather than an orphan that the next
     * attempt would duplicate.
     */
    private function partnerId(BillingoClient $client, InvoiceRequest $request): int
    {
        $email = $request->buyerEmail === null || trim($request->buyerEmail) === ''
            ? null
            : InvoicingPartner::normaliseEmail($request->buyerEmail);

        $billing = $request->billing;

        // No address, no mapping key. A fresh partner every time is the honest
        // outcome, and rare: the booking flows all collect an email.
        if ($email === null) {
            return $client->createPartner($billing->name ?? $request->buyerName, null, $billing);
        }

        $existing = InvoicingPartner::query()
            ->where('provider', InvoiceProvider::Billingo->value)
            ->where('email', $email)
            ->first();

        // A tenant can delete a partner in its own Billingo account; a stale row
        // would then fail every invoice for that customer forever.
        if ($existing instanceof InvoicingPartner && $client->partnerExists((int) $existing->partner_ref)) {
            return (int) $existing->partner_ref;
        }

        $partnerId = $client->createPartner($billing->name ?? $request->buyerName, $email, $billing);

        InvoicingPartner::query()->updateOrCreate(
            ['provider' => InvoiceProvider::Billingo->value, 'email' => $email],
            ['partner_ref' => (string) $partnerId],
        );

        return $partnerId;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function issued(BillingoClient $client, array $document): IssuedInvoice
    {
        $id = $document['id'] ?? null;

        if (! is_numeric($id)) {
            throw new RuntimeException('Billingo returned no document id.');
        }

        $number = (string) ($document['invoice_number'] ?? '');

        if ($number === '') {
            throw new RuntimeException('Billingo returned a document with no number.');
        }

        return new IssuedInvoice(
            number: $number,
            providerRef: (string) $id,
            pdf: $client->downloadDocument((int) $id),
        );
    }

    private function client(TenantInvoicingSettings $seller): BillingoClient
    {
        if (! $seller->hasApiKey()) {
            throw new RuntimeException('No Billingo API key is configured for this tenant.');
        }

        return new BillingoClient((string) $seller->apiKey);
    }

    /**
     * Minor units to whole currency units.
     *
     * Returns int|float rather than rounding: HUF amounts are multiples of 100
     * minor in practice, but silently dropping a remainder would put a different
     * number on a legal document than the customer was charged.
     */
    private function majorUnits(int $amountMinor): int|float
    {
        return $amountMinor % 100 === 0 ? intdiv($amountMinor, 100) : $amountMinor / 100;
    }

    private function vat(TenantInvoicingSettings $seller): string
    {
        $key = $seller->vatKey;

        // A numeric key is a percentage and needs the sign Billingo expects;
        // a lettered one ("AAM", "TAM") is already a Billingo VAT name.
        return is_numeric($key) ? $key.self::VAT_SUFFIX : $key;
    }
}
