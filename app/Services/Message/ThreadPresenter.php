<?php

namespace App\Services\Message;

use App\Models\Booking;
use App\Models\Message;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The page payload of one customer's thread (SLO-36), shared by the admin panel
 * and the members area so both sides render the same conversation. Times are
 * formatted in the tenant's timezone on the server (docs/01 §7).
 */
class ThreadPresenter
{
    /** The newest messages shown; older ones stay in the database and the export. */
    public const int THREAD_LIMIT = 200;

    /** How many recent bookings the "about this booking" picker offers. */
    public const int BOOKING_OPTIONS = 20;

    public function __construct(private readonly TenantManager $tenants) {}

    /**
     * The thread, oldest first. `$seesBooking` hides the code of an attached
     * booking the reader may not see — an employee reading a thread their
     * customer also has with a colleague must not learn that colleague's
     * booking through it.
     *
     * @param  (callable(Booking): bool)|null  $seesBooking
     * @return list<array{id: int, body: string, from_customer: bool, sender_id: int|null, sender_name: string|null, booking_code: string|null, created_local: string|null, read: bool}>
     */
    public function messages(User $customer, ?callable $seesBooking = null): array
    {
        $messages = Message::query()
            ->where('customer_id', $customer->getKey())
            ->with(['sender:id,name', 'booking:id,code,staff_id'])
            ->orderByDesc('id')
            ->limit(self::THREAD_LIMIT)
            ->get()
            ->reverse();

        return $messages->map(fn (Message $message): array => [
            'id' => $message->id,
            'body' => $message->body,
            'from_customer' => $message->from_customer,
            'sender_id' => $message->sender_id,
            'sender_name' => $message->sender?->name,
            'booking_code' => $message->booking !== null && ($seesBooking === null || $seesBooking($message->booking))
                ? $message->booking->code
                : null,
            'created_local' => $this->local($message->created_at),
            'read' => $message->read_at !== null,
        ])->values()->all();
    }

    /**
     * The customer's recent bookings a message can be attached to, narrowed by
     * the caller (an employee sees only their own).
     *
     * @param  (callable(Builder<Booking>): void)|null  $scope
     * @return list<array{id: int, code: string, label: string}>
     */
    public function bookingOptions(User $customer, ?callable $scope = null): array
    {
        $query = Booking::query()
            ->where('customer_id', $customer->getKey())
            ->with('service:id,name')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(self::BOOKING_OPTIONS);

        if ($scope !== null) {
            $scope($query);
        }

        return $query->get()->map(fn (Booking $booking): array => [
            'id' => $booking->id,
            'code' => $booking->code,
            'label' => trim(implode(' · ', array_filter([
                $booking->service?->name,
                $this->local($booking->starts_at),
                $booking->code,
            ]))),
        ])->values()->all();
    }

    public function local(?CarbonInterface $at): ?string
    {
        if ($at === null) {
            return null;
        }

        $timezone = $this->tenants->current()->timezone ?? (string) config('app.timezone');

        return $at->copy()->setTimezone($timezone)->format('Y-m-d H:i');
    }
}
