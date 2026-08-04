<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Services\Report\ReportRange;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Query filters for the tenant statistics module (SLO-137). `from`/`to` are
 * tenant-local calendar days, not instants — the timezone conversion happens in
 * {@see ReportRange} (docs/01 §7).
 *
 * The span cap is a real limit, not decoration: the report is served by live
 * queries over `bookings` (docs/02 §Statisztika), and an unbounded range would let
 * one request scan a tenant's whole history. Beyond a year the answer needs the
 * daily aggregate, which is a separate issue.
 */
class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::ReportView->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preset' => ['nullable', Rule::in(ReportRange::PRESETS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            // Which table the CSV export writes; ignored by the page itself.
            'section' => ['nullable', Rule::in(['daily', 'services', 'staff', 'rooms', 'customers'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $from = $this->input('from');
            $to = $this->input('to');

            if (! is_string($from) || ! is_string($to)) {
                return;
            }

            $days = Carbon::parse($from)->diffInDays(Carbon::parse($to));

            if (abs($days) + 1 > ReportRange::MAX_DAYS) {
                $validator->errors()->add('to', __('validation.custom.report_range.max_days', [
                    'days' => (string) ReportRange::MAX_DAYS,
                ]));
            }
        });
    }
}
