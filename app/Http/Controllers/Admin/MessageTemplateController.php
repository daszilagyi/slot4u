<?php

namespace App\Http\Controllers\Admin;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MessageTemplateRequest;
use App\Models\MessageTemplate;
use App\Services\Notification\MessageTemplateCatalog;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant email-template editor (SLO-114). Lists the editable notification kinds
 * with their built-in default and the tenant's override (if any), and upserts /
 * resets a single override. Lives behind auth + ensure.user.tenant +
 * can:template.manage (routes/tenant.php) — tenant-admin only per docs/03.
 *
 * Overrides are stored on the email channel in the tenant's own locale; the
 * BelongsToTenant scope keeps every read and write inside the current tenant.
 */
class MessageTemplateController extends Controller
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly MessageTemplateCatalog $catalog,
    ) {}

    public function index(): Response
    {
        $tenant = $this->tenants->current();
        abort_if($tenant === null, 404);
        Gate::authorize('viewAny', MessageTemplate::class);

        $overrides = MessageTemplate::query()
            ->where('channel', NotificationChannel::Email->value)
            ->where('locale', $tenant->locale)
            ->get()
            ->keyBy(fn (MessageTemplate $template): string => $template->key->value);

        $templates = array_map(function (NotificationType $type) use ($overrides): array {
            $override = $overrides->get($type->value);

            return [
                'key' => $type->value,
                'default' => [
                    'subject' => $this->catalog->defaultSubject($type),
                    'body' => $this->catalog->defaultBody($type),
                ],
                'override' => $override === null ? null : [
                    'subject' => $override->subject,
                    'body' => $override->body,
                    'enabled' => $override->enabled,
                ],
                'variables' => $this->catalog->variables($type),
            ];
        }, $this->catalog->editableTypes());

        return Inertia::render('Admin/Templates/Index', [
            'templates' => $templates,
            'locale' => $tenant->locale,
        ]);
    }

    public function update(MessageTemplateRequest $request, string $tenant, string $key): RedirectResponse
    {
        $tenant = $this->tenants->current();
        abort_if($tenant === null, 404);
        Gate::authorize('create', MessageTemplate::class);

        $type = $this->editableType($key);

        // Scoped by the BelongsToTenant global scope + the tenant-stamping creating
        // hook, so the match and the insert both stay inside the current tenant.
        MessageTemplate::query()->updateOrCreate(
            [
                'key' => $type->value,
                'channel' => NotificationChannel::Email->value,
                'locale' => $tenant->locale,
            ],
            $request->validated(),
        );

        return back();
    }

    public function destroy(string $tenant, string $key): RedirectResponse
    {
        $tenant = $this->tenants->current();
        abort_if($tenant === null, 404);
        Gate::authorize('create', MessageTemplate::class);

        $type = $this->editableType($key);

        MessageTemplate::query()
            ->where('key', $type->value)
            ->where('channel', NotificationChannel::Email->value)
            ->where('locale', $tenant->locale)
            ->delete();

        return back();
    }

    /**
     * Resolve a route key to an editable notification type, or 404 — an unknown or
     * non-editable (e.g. payment_*) key is not a template a tenant may manage.
     */
    private function editableType(string $key): NotificationType
    {
        $type = NotificationType::tryFrom($key);
        abort_if($type === null || ! $this->catalog->isEditable($type), 404);

        return $type;
    }
}
