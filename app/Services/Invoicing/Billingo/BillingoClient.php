<?php

declare(strict_types=1);

namespace App\Services\Invoicing\Billingo;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A thin HTTP client for the Billingo v3 API (SLO-167).
 *
 * Constructed per call with the TENANT's own key — never injected as a singleton,
 * because there is no such thing as "the" Billingo credential here: every tenant
 * invoices from its own account.
 *
 * Deliberately small. It speaks the six endpoints the adapter needs and nothing
 * else; the mapping between our domain and Billingo's document shape belongs in
 * {@see BillingoInvoiceIssuer}, so this class stays a transport and can be read
 * against the API documentation line by line.
 *
 * Contract: https://api.swaggerhub.com/apis/Billingo/Billingo/3.0.14 (OpenAPI
 * 3.0.14), base https://api.billingo.hu/v3, auth via the `X-API-KEY` header.
 */
final class BillingoClient
{
    public const BASE_URL = 'https://api.billingo.hu/v3';

    public function __construct(private readonly string $apiKey) {}

    /**
     * The tenant's document blocks — every invoice must name one.
     *
     * @return list<array{id: int, name: string, type: string}>
     */
    public function documentBlocks(): array
    {
        return $this->list($this->request()->get(self::BASE_URL.'/document-blocks'), ['id', 'name', 'type']);
    }

    /**
     * The tenant's bank accounts. Optional on a document, so an empty list is a
     * normal state, not a misconfiguration.
     *
     * @return list<array{id: int, name: string, account_number: string}>
     */
    public function bankAccounts(): array
    {
        return $this->list($this->request()->get(self::BASE_URL.'/bank-accounts'), ['id', 'name', 'account_number']);
    }

    /**
     * Create a partner and return its id.
     *
     * ⚠️ Billingo has no "find by email": `GET /partners?query=` matches on NAME
     * only (verified against the live API — an email query returns nothing). So
     * this is create-only, and not repeating it is the caller's job through the
     * stored mapping. Searching by name instead would collide on common names and
     * break the moment a customer's name is edited.
     */
    public function createPartner(string $name, ?string $email): int
    {
        $response = $this->request()->post(self::BASE_URL.'/partners', array_filter([
            'name' => $name,
            'emails' => $email === null ? null : [$email],
            // A private individual: the tax fields stay empty rather than absent,
            // which is what the API expects for "no tax number".
            'taxcode' => '',
            'tax_type' => 'NO_TAX_NUMBER',
        ], static fn ($value): bool => $value !== null));

        $id = $this->json($response)['id'] ?? null;

        if (! is_numeric($id)) {
            throw new RuntimeException('Billingo returned no partner id.');
        }

        return (int) $id;
    }

    /** Whether a partner id still exists — a tenant can delete one behind our back. */
    public function partnerExists(int $id): bool
    {
        return $this->request()->get(self::BASE_URL.'/partners/'.$id)->successful();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createDocument(array $payload): array
    {
        return $this->json($this->request()->post(self::BASE_URL.'/documents', $payload));
    }

    /**
     * Void a document. Billingo answers with a NEW document — the cancellation —
     * carrying its own number and a negative total, rather than editing the
     * original (verified live).
     *
     * @return array<string, mixed>
     */
    public function cancelDocument(int $id): array
    {
        return $this->json($this->request()->post(self::BASE_URL.'/documents/'.$id.'/cancel'));
    }

    /** The rendered PDF bytes. */
    public function downloadDocument(int $id): string
    {
        $response = $this->request()->get(self::BASE_URL.'/documents/'.$id.'/download');

        $this->assertOk($response);

        return $response->body();
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders([
            'X-API-KEY' => $this->apiKey,
            'Accept' => 'application/json',
        ])
            // A short ceiling on purpose: this runs inside a queued job that has
            // its own retry with backoff, so waiting a minute on a wedged
            // connection buys nothing the job does not already provide.
            ->timeout(20)
            ->connectTimeout(10)
            ->acceptJson();
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function list(Response $response, array $keys): array
    {
        $rows = $this->json($response)['data'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = array_intersect_key($row, array_flip($keys));
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $this->assertOk($response);

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Turn a refusal into an exception carrying Billingo's own words.
     *
     * The message matters: it is stored on the invoice row and is the only thing
     * an admin has to act on ("the block was deleted", "the key is revoked").
     * A generic "invoicing failed" would make every failure look the same.
     */
    private function assertOk(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $body = $response->json();
        $message = is_array($body) ? (string) ($body['message'] ?? '') : '';

        if (is_array($body) && is_array($body['errors'] ?? null)) {
            $details = [];

            foreach ($body['errors'] as $error) {
                if (is_array($error)) {
                    $details[] = trim(((string) ($error['field'] ?? '')).' '.((string) ($error['message'] ?? '')));
                }
            }

            if ($details !== []) {
                $message .= ' ('.implode('; ', $details).')';
            }
        }

        throw new RuntimeException(sprintf(
            'Billingo HTTP %d%s',
            $response->status(),
            $message === '' ? '' : ': '.$message,
        ));
    }
}
