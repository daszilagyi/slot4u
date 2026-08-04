<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReportFilterRequest;
use App\Services\Report\BuildTenantReport;
use App\Services\Report\ReportRange;
use App\Services\Report\TenantReport;
use App\Support\Hundredths;
use App\Tenancy\TenantManager;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tenant statistics module (SLO-137 / SLO-45, docs/05 M7). Behind auth +
 * ensure.user.tenant + ensure.feature:feature_reports + can:report.view
 * (routes/tenant.php) — the feature answers whether the TENANT has the module, the
 * permission whether this USER may read it (docs/03).
 *
 * Every number is assembled by {@see BuildTenantReport}; both the page and the CSV
 * export read the same object, so an exported figure can never differ from the one
 * on screen.
 */
class ReportController extends Controller
{
    public function index(ReportFilterRequest $request, BuildTenantReport $build): Response
    {
        $report = $this->report($request, $build);

        return Inertia::render('Admin/Reports/Index', [
            'report' => $report->toArray(),
            'presets' => ReportRange::PRESETS,
        ]);
    }

    /**
     * One table of the report as CSV. Which one is the `section` filter; the range
     * filters are the same as the page's, so "export what I am looking at" is a plain
     * link with the current query string.
     */
    public function export(ReportFilterRequest $request, BuildTenantReport $build): StreamedResponse
    {
        $tenant = app(TenantManager::class)->current();
        abort_if($tenant === null, 404);

        $report = $this->report($request, $build);
        $section = $request->validated('section');
        $section = is_string($section) ? $section : 'daily';

        [$headers, $rows] = $this->table($report, $section);

        $filename = "slot4u-report-{$tenant->slug}-{$section}-{$report->from}_{$report->to}.csv";

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'wb');

            // BOM so Excel opens the Hungarian accents as UTF-8 rather than latin1.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ';');

            foreach ($rows as $row) {
                fputcsv($out, $row, ';');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function report(ReportFilterRequest $request, BuildTenantReport $build): TenantReport
    {
        $tenant = app(TenantManager::class)->current();
        abort_if($tenant === null, 404);

        $actor = $request->user();
        abort_if($actor === null, 403);

        /** @var array<string, mixed> $filters */
        $filters = $request->validated();

        return $build($tenant, $actor, $filters);
    }

    /**
     * Header row + data rows for one section. Money is a decimal string a spreadsheet
     * sums as currency ({@see Hundredths}, integer arithmetic per docs/01 §6) and
     * rates are percents rather than basis points. Time stays in whole minutes: hours
     * would need a rounding rule, and a column of exact integers is the one thing a
     * spreadsheet can always re-derive from.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function table(TenantReport $report, string $section): array
    {
        return match ($section) {
            'services' => [
                [
                    trans('app.admin.reports.export.service'),
                    trans('app.admin.reports.export.bookings'),
                    trans('app.admin.reports.export.revenue'),
                ],
                array_map(fn (array $row) => [
                    (string) ($row['name'] ?? '—'),
                    (string) $row['bookings'],
                    Hundredths::format($row['revenue_minor']),
                ], $report->byService),
            ],
            'staff', 'rooms' => [
                [
                    trans($section === 'rooms' ? 'app.admin.reports.export.room' : 'app.admin.reports.export.staff'),
                    trans('app.admin.reports.export.bookings'),
                    trans('app.admin.reports.export.revenue'),
                    trans('app.admin.reports.export.booked_minutes'),
                    trans('app.admin.reports.export.open_minutes'),
                    trans('app.admin.reports.export.utilization'),
                ],
                array_map(fn (array $row) => [
                    $row['name'],
                    (string) $row['bookings'],
                    Hundredths::format($row['revenue_minor']),
                    (string) $row['booked_minutes'],
                    (string) $row['scheduled_minutes'],
                    $row['utilization_bps'] !== null ? Hundredths::percent($row['utilization_bps']) : '',
                ], $section === 'rooms' ? $report->byRoom : $report->byStaff),
            ],
            'customers' => [
                [
                    trans('app.admin.reports.export.customer'),
                    trans('app.admin.reports.export.guest'),
                    trans('app.admin.reports.export.bookings'),
                    trans('app.admin.reports.export.spend'),
                ],
                array_map(fn (array $row) => [
                    $row['name'],
                    $row['is_guest'] ? trans('app.admin.reports.export.guest_yes') : '',
                    (string) $row['bookings'],
                    Hundredths::format($row['spend_minor']),
                ], $report->topCustomers),
            ],
            default => [
                [
                    trans('app.admin.reports.export.date'),
                    trans('app.admin.reports.export.bookings'),
                    trans('app.admin.reports.export.revenue'),
                ],
                array_map(fn (array $row) => [
                    $row['date'],
                    (string) $row['bookings'],
                    Hundredths::format($row['revenue_minor']),
                ], $report->series),
            ],
        };
    }
}
