<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\Booking;
use App\Models\CommissionInvoice;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Payment;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestMessage;
use App\Models\Room;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The tenant's own complete data set, as a streamed JSON document (SLO-160,
 * docs/19 §7.4).
 *
 * This is not a data-subject export — that is {@see PersonalDataExport}, one
 * customer's copy under art. 15. This is the *controller's* copy: a tenant that
 * has left, or is about to, may take its bookings, customers, services and
 * invoices with it. Without it the 90-day purge would destroy records the tenant
 * itself is legally required to keep, and a tenant cannot be asked to trust a
 * deadline it has no way to prepare for.
 *
 * ## Streamed, not assembled
 *
 * A busy tenant's history does not fit comfortably in memory, and this endpoint
 * is reachable by a tenant admin on a shared host with a modest memory limit.
 * Every section is therefore written straight to the response from a database
 * cursor; nothing larger than one row is held at a time.
 *
 * ## What is deliberately left out
 *
 * - **`tenants.invoicing`** — it holds the tenant's invoicing-provider API key.
 *   The tenant already has that credential; re-emitting it into a file that will
 *   sit in a downloads folder only creates a second place to leak it from.
 * - **Password hashes and remember tokens**, via the models' own `$hidden`.
 * - **The audit trail**, which is slot4u's platform-level security record with
 *   its own legal basis and retention window, not tenant-owned data.
 */
final class TenantDataExport
{
    /**
     * Every section of the export, in order, as JSON fragments.
     *
     * @return Generator<int, string>
     */
    public function stream(Tenant $tenant): Generator
    {
        yield '{';
        yield $this->key('generated_at').$this->encode(Carbon::now()->toIso8601String()).',';
        yield $this->key('tenant').$this->encode($this->tenantProfile($tenant)).',';

        $sections = $this->sections($tenant);
        $last = array_key_last($sections);

        foreach ($sections as $name => $query) {
            yield from $this->section($name, $query);
            yield $name === $last ? '' : ',';
        }

        yield '}';
    }

    /** Suggested download filename — the slug and the day it was taken. */
    public function filename(Tenant $tenant): string
    {
        return 'slot4u-'.$tenant->slug.'-'.Carbon::now()->format('Y-m-d').'-adatexport.json';
    }

    /**
     * The tenant row itself, hand-built rather than `toArray()`d: the model
     * casts `invoicing` to an encrypted array, so serialising it wholesale would
     * decrypt the provider API key straight into the file.
     *
     * @return array<string, mixed>
     */
    private function tenantProfile(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status->value,
            'timezone' => $tenant->timezone,
            'locale' => $tenant->locale,
            'settings' => $tenant->settings,
            'branding' => $tenant->branding,
            'created_at' => $tenant->created_at?->toIso8601String(),
            'archived_at' => $tenant->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * The exported sections, each as an unexecuted, id-ordered query.
     *
     * Every one filters `tenant_id` explicitly rather than leaning on the global
     * scope: the export must produce the same file from a queued job or a
     * console command, where the scope is silent (docs/19 §4).
     *
     * @return array<string, Builder<covariant Model>>
     */
    private function sections(Tenant $tenant): array
    {
        $id = $tenant->getKey();

        return [
            'locations' => Location::query()->where('tenant_id', $id)->orderBy('id'),
            'rooms' => Room::query()->where('tenant_id', $id)->orderBy('id'),
            'staff' => Staff::query()->where('tenant_id', $id)->orderBy('id'),
            'service_categories' => ServiceCategory::query()->where('tenant_id', $id)->orderBy('id'),
            'services' => Service::query()->where('tenant_id', $id)->orderBy('id'),
            'customers' => User::query()->where('tenant_id', $id)->orderBy('id'),
            'bookings' => Booking::query()->where('tenant_id', $id)->orderBy('id'),
            'quote_requests' => QuoteRequest::query()->where('tenant_id', $id)->orderBy('id'),
            'quote_request_messages' => QuoteRequestMessage::query()->where('tenant_id', $id)->orderBy('id'),
            'waitlist_entries' => WaitlistEntry::query()->where('tenant_id', $id)->orderBy('id'),
            'payments' => Payment::query()->where('tenant_id', $id)->orderBy('id'),
            'invoices' => Invoice::query()->where('tenant_id', $id)->orderBy('id'),
            // slot4u's own invoices to the tenant. They are the tenant's
            // purchase records as much as slot4u's sales records, and it needs
            // them for the same eight years (Szt. 169. §).
            'commission_invoices' => CommissionInvoice::query()->where('tenant_id', $id)->orderBy('id'),
        ];
    }

    /**
     * One `"name": [ … ]` section, written row by row from a cursor.
     *
     * @param  Builder<covariant Model>  $query
     * @return Generator<int, string>
     */
    private function section(string $name, Builder $query): Generator
    {
        yield $this->key($name).'[';

        $first = true;

        foreach ($query->cursor() as $model) {
            yield ($first ? '' : ',').$this->encode($model->toArray());
            $first = false;
        }

        yield ']';
    }

    private function key(string $name): string
    {
        return $this->encode($name).':';
    }

    private function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
