<?php

namespace App\Events;

use App\Models\CommissionInvoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A monthly commission invoice was issued to a tenant (docs/10 §6.5). Listeners
 * email the tenant's admins and (later, J-follow-up) push it to an external
 * invoicing provider + integration_logs.
 */
class CommissionInvoiceIssued
{
    use Dispatchable;

    public function __construct(public readonly CommissionInvoice $invoice) {}
}
