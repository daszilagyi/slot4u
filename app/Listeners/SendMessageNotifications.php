<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Events\MessageSent;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\CustomerMessageNotification;
use App\Notifications\MessageReceivedNotification;
use App\Services\Notification\CustomerNotifier;
use App\Support\CustomerVisibility;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

/**
 * Mails the other side of a thread when a message arrives (SLO-36): the customer
 * when staff replied, the staff who may answer when the customer wrote.
 *
 * ⚠️ One mail per unread burst, not one per message. Only the first unread
 * message in that direction mails; the ones that follow before the thread is
 * opened stay quiet. Someone typing three short messages in a row would
 * otherwise send three mails saying the same thing.
 */
class SendMessageNotifications
{
    public function __construct(
        private readonly CustomerNotifier $notifier,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function handle(MessageSent $event): void
    {
        $message = $event->message;
        $tenant = $this->notifier->operationalTenant($message);

        if ($tenant === null || $this->alreadyWaiting($message)) {
            return;
        }

        $message->loadMissing('customer');
        $customer = $message->customer;

        if ($customer === null) {
            return;
        }

        if ($message->from_customer) {
            $notification = new CustomerMessageNotification($message, $customer->name, $tenant);

            foreach ($this->staffRecipients($tenant, $customer) as $staff) {
                $staff->notify($notification);
            }

            return;
        }

        $this->notifier->sendToCustomer(
            tenant: $tenant,
            type: NotificationType::MessageReceived,
            dedupeKey: 'message:'.$message->getKey(),
            customer: $customer,
            notification: new MessageReceivedNotification($tenant),
        );
    }

    /** Whether an earlier message in the same direction is still unread. */
    private function alreadyWaiting(Message $message): bool
    {
        return Message::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $message->tenant_id)
            ->where('customer_id', $message->customer_id)
            ->where('from_customer', $message->from_customer)
            ->whereNull('read_at')
            ->where('id', '<', $message->getKey())
            ->exists();
    }

    /**
     * The tenant's staff who hold `message.send` and may see this customer — the
     * same people who would find the thread in their inbox.
     *
     * @return Collection<int, User>
     */
    private function staffRecipients(Tenant $tenant, User $customer): Collection
    {
        $previousTeamId = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($tenant->getKey());

        try {
            return User::query()
                ->where('tenant_id', $tenant->getKey())
                ->whereKeyNot($customer->getKey())
                ->whereNotNull('email')
                ->get()
                ->filter(fn (User $user): bool => $user->isStaff()
                    && $user->can(Permission::MessageSend->value)
                    && CustomerVisibility::owns($user, $customer))
                ->values();
        } finally {
            $this->registrar->setPermissionsTeamId($previousTeamId);
        }
    }
}
