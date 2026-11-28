<?php

namespace App\Http\Controllers;

use App\Models\ShopSettings;
use App\Services\AccessAudit;
use App\Services\FinancialReportService;
use App\Services\PostingTransaction;
use App\Services\ReportDetailService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinancialReportController extends Controller
{
    public function index(Request $request, FinancialReportService $service): Response
    {
        [$from, $to] = $this->dates($request);

        return Inertia::render('reports/financial', PostingTransaction::run(function () use ($request, $service, $from, $to): array {
            ShopSettings::query()->lockForUpdate()->findOrFail(1);

            return ['report' => $service->build($request->user(), $from, $to), 'detail' => app(ReportDetailService::class)->build($request->user(), $from, $to)];
        }, 'report'));
    }

    public function export(Request $request, FinancialReportService $service): StreamedResponse
    {
        [$from, $to] = $this->dates($request);
        $report = $service->build($request->user(), $from, $to);
        AccessAudit::record('financial_report.exported', $request->user(), null, 'Owner downloaded a financial report snapshot.', null, ['from' => $from, 'to' => $to, 'generated_at' => $report['generated_at']]);

        return response()->streamDownload(function () use ($report): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                throw new \RuntimeException('Could not open report export stream.');
            }
            try {
                fputcsv($stream, ['section', 'account_id', 'account_name', 'metric', 'value', 'unit', 'from_nairobi', 'through_nairobi', 'snapshot_utc'], ',', '"', '');
                fputcsv($stream, ['metadata', '', '', 'opening_ready', $report['opening_ready'] ? 'true' : 'false', 'boolean', $report['from'], $report['to'], $report['generated_at']], ',', '"', '');
                foreach (['summary', 'current'] as $section) {
                    foreach ($report[$section] as $metric => $value) {
                        fputcsv($stream, [$section, '', '', $metric, $value ?? 'INCOMPLETE', is_int($value) ? 'count' : 'KES', $report['from'], $report['to'], $report['generated_at']], ',', '"', '');
                    }
                }
                foreach ($report['employees'] as $employee) {
                    // Prefix untrusted account names so spreadsheet apps cannot execute a formula.
                    $name = preg_match('/^[=+@\-\t\r\n]/', ltrim($employee['name'], ' ')) === 1 ? "'".$employee['name'] : $employee['name'];
                    foreach ($employee as $metric => $value) {
                        if (in_array($metric, ['id', 'name', 'active'], true)) {
                            continue;
                        }
                        fputcsv($stream, ['employee', $employee['id'], $name, $metric, $value, $metric === 'sale_count' ? 'count' : 'KES', $report['from'], $report['to'], $report['generated_at']], ',', '"', '');
                    }
                }
            } finally {
                fclose($stream);
            }
        }, 'kaura-financial-'.$from.'-to-'.$to.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** @return array{string, string} */
    private function dates(Request $request): array
    {
        $today = CarbonImmutable::now('Africa/Nairobi')->toDateString();
        $request->mergeIfMissing(['from' => $today, 'to' => $today]);
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$today]]);
        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 365) {
            throw ValidationException::withMessages(['to' => 'Select up to 366 inclusive Nairobi business days.']);
        }

        return [$data['from'], $data['to']];
    }
}
