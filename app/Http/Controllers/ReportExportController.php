<?php

namespace App\Http\Controllers;

use App\Services\AccessAudit;
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
        $data = $request->validate(['dataset' => ['required', Rule::in(['products', 'debts', 'variance', 'operations', 'handovers', 'damaged', 'daily'])], 'from' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$today]]);
        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 365) {
            throw ValidationException::withMessages(['to' => 'Select up to 366 days.']);
        }
        if ($data['dataset'] === 'variance') {
            $rows = DB::table('stock_count_items')->join('stock_counts', 'stock_counts.id', '=', 'stock_count_items.stock_count_id')->whereBetween('stock_counts.counted_at', [CarbonImmutable::parse($data['from'], 'Africa/Nairobi')->utc(), CarbonImmutable::parse($data['to'], 'Africa/Nairobi')->endOfDay()->utc()])->select('stock_counts.reference', 'stock_counts.counted_at', 'stock_counts.status', 'stock_counts.reason', 'stock_count_items.*')->get()->map(fn (object $row): array => (array) $row)->all();
        } elseif ($data['dataset'] === 'operations') {
            $rows = DB::table('shop_operations')->whereBetween('business_date', [$data['from'], $data['to']])->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        } else {
            $dataset = $service->build($data['from'], $data['to'])[$data['dataset']];
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
