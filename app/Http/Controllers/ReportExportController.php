<?php

namespace App\Http\Controllers;

use App\Services\AccessAudit;
use App\Services\FinancialReportService;
use App\Services\ReportDetailService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function export(Request $request, ReportDetailService $service): StreamedResponse
    {
        $today = now('Africa/Nairobi')->toDateString();
        $request->mergeIfMissing(['from' => $today, 'to' => $today]);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'low_stock' => ['nullable', 'boolean'], 'overdue' => ['nullable', 'boolean'], 'employee_id' => ['nullable', 'integer', 'exists:users,id'], 'dataset' => ['required', Rule::in(['products', 'debts', 'variance', 'operations', 'handovers', 'damaged', 'daily', 'employees'])], 'from' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$today]]);
        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 365) {
            throw ValidationException::withMessages(['to' => 'Select up to 366 days.']);
        }
        if ($data['dataset'] === 'variance') {
            $rows = DB::table('stock_count_items')->join('stock_counts', 'stock_counts.id', '=', 'stock_count_items.stock_count_id')->whereBetween('stock_counts.counted_at', [CarbonImmutable::parse($data['from'], 'Africa/Nairobi')->utc(), CarbonImmutable::parse($data['to'], 'Africa/Nairobi')->endOfDay()->utc()])->select('stock_counts.reference', 'stock_counts.counted_at', 'stock_counts.status', 'stock_counts.reason', 'stock_count_items.*')->get()->map(fn (object $row): array => (array) $row)->all();
        } elseif ($data['dataset'] === 'operations') {
            $rows = DB::table('shop_operations')->whereBetween('business_date', [$data['from'], $data['to']])->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        } elseif ($data['dataset'] === 'employees') {
            $rows = app(FinancialReportService::class)->build($request->user(), $data['from'], $data['to'])['employees'];
        } else {
            $dataset = $service->build($request->user(), $data['from'], $data['to'])[$data['dataset']];
            if (! is_iterable($dataset)) {
                throw new \LogicException('Report dataset must contain rows.');
            }
            $rows = [];
            foreach ($dataset as $row) {
                if (! is_array($row) && ! is_object($row)) {
                    throw new \LogicException('Report rows must contain fields.');
                }
                $rows[] = (array) $row;
            }
        }
        $rows = array_values(array_filter($rows, function (array $row) use ($data, $today): bool {
            if ($data['dataset'] === 'products') {
                $text = $row['name'].' '.$row['sku'].' '.$row['category'];

                return (empty($data['q']) || str_contains(mb_strtolower($text), mb_strtolower($data['q']))) && (empty($data['low_stock']) || ($row['active'] && $row['low']));
            }
            if ($data['dataset'] === 'debts') {
                $text = ($row['name'] ?? '').' '.($row['contact'] ?? '').' '.$row['id'];

                return (empty($data['q']) || str_contains(mb_strtolower($text), mb_strtolower($data['q']))) && (empty($data['overdue']) || $row['due_on'] < $today);
            }

            return empty($data['employee_id']) || ! in_array($data['dataset'], ['employees', 'handovers'], true) || (int) ($row['employee_id'] ?? $row['id']) === (int) $data['employee_id'];
        }));
        AccessAudit::record('report.dataset_exported', $request->user(), null, 'Owner exported '.$data['dataset'].'.', null, $data);

        return response()->streamDownload(function () use ($rows, $data): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                throw new \RuntimeException('Cannot open CSV stream.');
            }
            try {
                fputcsv($stream, ['report', $data['dataset'], 'from_nairobi', $data['from'], 'through_nairobi', $data['to'], 'balances', 'current at export; monetary fields ending _minor are KES cents'], ',', '"', '');
                if ($rows !== []) {
                    fputcsv($stream, array_keys($rows[0]), ',', '"', '');
                }
                foreach ($rows as $row) {
                    fputcsv($stream, array_map(function ($value): string {
                        $value = is_bool($value) ? ($value ? 'yes' : 'no') : (string) ($value ?? '');

                        return preg_match('/^[=+@\-\t\r\n]/', ltrim($value, ' ')) === 1 ? "'".$value : $value;
                    }, $row), ',', '"', '');
                }
            } finally {
                fclose($stream);
            }
        }, 'kaura-'.$data['dataset'].'-'.$data['from'].'-'.$data['to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
