<?php

declare(strict_types=1);

namespace App\Services\Domain;

use App\Models\TenantDomain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare for SaaS custom hostnames (SLO-135, docs/01).
 *
 * Cloudflare only serves hostnames registered on the zone, so this is what
 * turns a verified tenant domain into one that actually loads — without it the
 * tenant's CNAME lands on error 1014, not the booking page.
 *
 * Certificate validation is `http`: the domain already CNAMEs to our fallback
 * origin by the time we register it, so Cloudflare can serve the validation
 * challenge itself and the tenant does not have to publish a second DNS record
 * on top of the ownership TXT they already added.
 */
class CloudflareCustomHostnameProvisioner implements CustomHostnameProvisioner
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private readonly ?string $token,
        private readonly ?string $zoneId,
        private readonly int $timeout = 15,
    ) {}

    public function isConfigured(): bool
    {
        return $this->token !== null && $this->token !== ''
            && $this->zoneId !== null && $this->zoneId !== '';
    }

    public function provision(TenantDomain $domain): ProvisionedHostname
    {
        // Look first: re-registering a hostname Cloudflare already knows is an
        // error, and a retry after a timed-out create must not trip over the
        // registration its own first attempt actually made.
        $existing = $this->find($domain->domain);

        if ($existing !== null) {
            return $existing;
        }

        $response = $this->send(fn (PendingRequest $http): Response => $http->post("/zones/{$this->zoneId}/custom_hostnames", [
            'hostname' => $domain->domain,
            'ssl' => [
                'method' => 'http',
                'type' => 'dv',
                'settings' => ['min_tls_version' => '1.2'],
            ],
        ]));

        return $this->toHostname($this->result($response));
    }

    public function refresh(TenantDomain $domain): ProvisionedHostname
    {
        $id = $domain->provider_hostname_id;

        // Fall back to a lookup by name: an interrupted provision can leave a
        // hostname registered at Cloudflare with no id stored on our side.
        if ($id === null || $id === '') {
            $found = $this->find($domain->domain);

            if ($found === null) {
                throw ProvisioningFailed::fromApi("Custom hostname {$domain->domain} is not registered.");
            }

            return $found;
        }

        $response = $this->send(fn (PendingRequest $http): Response => $http->get("/zones/{$this->zoneId}/custom_hostnames/{$id}"));

        return $this->toHostname($this->result($response));
    }

    public function deprovision(string $providerHostnameId): void
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->delete("/zones/{$this->zoneId}/custom_hostnames/{$providerHostnameId}"));

        // Already gone is the outcome we wanted, not a failure.
        if ($response->status() === 404) {
            return;
        }

        $this->result($response);
    }

    /**
     * The registration for a hostname, or null if Cloudflare has none.
     */
    private function find(string $hostname): ?ProvisionedHostname
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->get("/zones/{$this->zoneId}/custom_hostnames", [
            'hostname' => $hostname,
        ]));

        $result = $this->result($response);

        // The list endpoint returns an array; an exact-hostname filter still
        // matches by prefix on some plans, so confirm the name.
        foreach ($result as $row) {
            if (is_array($row) && ($row['hostname'] ?? null) === $hostname) {
                return $this->toHostname($row);
            }
        }

        return null;
    }

    /**
     * Every call goes through here so a network failure surfaces as the same
     * retryable ProvisioningFailed as a refusal, instead of an HTTP client
     * exception the callers would have to know about.
     *
     * @param  \Closure(PendingRequest): Response  $call
     */
    private function send(\Closure $call): Response
    {
        try {
            return $call($this->request());
        } catch (ConnectionException $e) {
            throw ProvisioningFailed::fromApi($e->getMessage());
        }
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE)
            ->withToken((string) $this->token)
            ->acceptJson()
            ->timeout($this->timeout);
    }

    /**
     * Cloudflare answers 200 with `success: false` as readily as it answers a
     * 4xx, so both are unwrapped here into the same exception.
     *
     * @return array<mixed>
     */
    private function result(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw ProvisioningFailed::fromApi("Cloudflare returned an unreadable response (HTTP {$response->status()}).");
        }

        if ($response->failed() || ($body['success'] ?? false) !== true) {
            throw ProvisioningFailed::fromApi($this->errorMessage($body, $response->status()));
        }

        $result = $body['result'] ?? null;

        return is_array($result) ? $result : [];
    }

    /**
     * @param  array<mixed>  $body
     */
    private function errorMessage(array $body, int $status): string
    {
        $errors = $body['errors'] ?? null;

        $messages = [];

        foreach (is_array($errors) ? $errors : [] as $error) {
            if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
                $code = isset($error['code']) ? " ({$error['code']})" : '';
                $messages[] = $error['message'].$code;
            }
        }

        return $messages === []
            ? "Cloudflare rejected the request (HTTP {$status})."
            : implode('; ', $messages);
    }

    /**
     * @param  array<mixed>  $result
     */
    private function toHostname(array $result): ProvisionedHostname
    {
        $id = $result['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw ProvisioningFailed::fromApi('Cloudflare returned a custom hostname without an id.');
        }

        $ssl = $result['ssl'] ?? null;
        $status = is_array($ssl) ? ($ssl['status'] ?? null) : null;

        return new ProvisionedHostname($id, is_string($status) ? $status : null);
    }
}
