<?php

namespace App\Enums;

use App\Actions\Quote\ChangeQuoteRequestStatus;

/**
 * The lifecycle of a quote request (docs/04 §6, SLO-27). A non-calendar,
 * ask-first booking mode: the customer submits a form, the tenant works it up
 * into a price, and — if accepted — an optional booking is generated.
 *
 *   new ──▶ in_progress ──▶ quoted ──accept──▶ accepted
 *    │           │             │
 *    └──reject───┴──reject─────┴──reject──▶ rejected
 *
 * The allowed transitions are the single source of truth enforced by
 * {@see ChangeQuoteRequestStatus}. `accepted` and `rejected` are terminal.
 */
enum QuoteRequestStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Quoted = 'quoted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    /**
     * The statuses this one may transition to (docs/04 §6). A request may be
     * rejected from any non-terminal state.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::InProgress, self::Rejected],
            self::InProgress => [self::Quoted, self::Rejected],
            self::Quoted => [self::Accepted, self::Rejected],
            self::Accepted, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Terminal states have no outgoing transitions.
     */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
