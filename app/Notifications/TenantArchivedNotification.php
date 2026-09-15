<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Http\Controllers\Super\TenantController;
use App\Models\Tenant;
use App\Notifications\Concerns\SuppressedForDemoTenant;
use App\Services\Mail\MailTextStore;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Tells a tenant's admins that their workspace has been archived, and on what
 * date its data will be erased (SLO-160, docs/19 §7).
 *
 * The 90-day grace period is only defensible if the controller knows about it:
 * a tenant that is never told cannot exercise the one thing the window is for
 * — taking its own records with it before they are gone. The mail therefore
 * names the exact date and how to get a copy.
 *
 * It points at slot4u support rather than a self-service link on purpose.
 * Archiving soft-deletes the tenant, so its subdomain 404s from that moment and
 * the in-app export is unreachable; during the window slot4u produces the file
 * from the superadmin panel instead
 * ({@see TenantController::export()}).
 */
class TenantArchivedNotification extends Notification
{
    use Queueable, SuppressedForDemoTenant;

    public function __construct(
        private readonly Tenant $tenant,
        private readonly Carbon $purgeAt,
    ) {
        $this->locale = $tenant->locale;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // The superadmin's wording if edited (SLO-246), else the lang default.
        $mail = app(MailTextStore::class)->resolve('tenant_archived', $this->tenant->locale)->applyTo(
            new MailMessage,
            ['name' => $notifiable->name, 'tenant' => $this->tenant->name, 'date' => $this->deadline()],
        );

        return $this->suppressWhenDemo($mail, $this->tenant);
    }

    /** The purge date rendered in the tenant's own timezone. */
    private function deadline(): string
    {
        return $this->purgeAt->copy()->setTimezone($this->tenant->timezone)->isoFormat('LL');
    }
}
