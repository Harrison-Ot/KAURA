<?php

namespace App\Http\Controllers;

use App\Http\Requests\OwnerOutflowRequest;
use App\Models\CashOutflow;
use App\Models\ShopSettings;
use App\Services\AccessAudit;
use App\Services\OwnerOutflowService;
use App\Support\CatalogueValues;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OutflowController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', CashOutflow::class);
        [$query, $filters] = $this->filtered($request);
        $totals = [];
        foreach (['stock_purchase', 'expense', 'owner_withdrawal', 'refund'] as $kind) {
            $totals[$kind] = CatalogueValues::money((int) (clone $query)->where('cash_outflows.kind', $kind)->sum('amount_minor'));
        }
        foreach (['cash', 'mpesa'] as $method) {
            $totals[$method] = CatalogueValues::money((int) (clone $query)->where('cash_outflows.payment_method', $method)->sum('amount_minor'));
        }

        return Inertia::render('outflows/index', ['outflows' => $query->orderByDesc('cash_outflows.id')->paginate(20, ['cash_outflows.*', 'users.name as actor_name', 'inventory_batches.supplier_name', 'inventory_batches.source_note', 'inventory_batches.reference as batch_reference'])->withQueryString()->through(fn (CashOutflow $outflow): array => $this->present($outflow)), 'totals' => $totals, 'filters' => $filters]);
    }

    public function create(): Response
    {
        Gate::authorize('create', CashOutflow::class);

        return Inertia::render('outflows/create', ['submission_key' => (string) Str::uuid(), 'openingReady' => ShopSettings::query()->findOrFail(1)->opening_completed_at !== null, 'today' => now('Africa/Nairobi')->toDateString()]);
    }

    public function store(OwnerOutflowRequest $request, OwnerOutflowService $service): RedirectResponse
    {
        return to_route('owner.outflows.show', $service->record($request->user(), $request->validated()));
    }

    public function show(CashOutflow $outflow): Response
    {
        Gate::authorize('view', $outflow);
        $outflow = CashOutflow::query()->leftJoin('inventory_batches', 'inventory_batches.id', '=', 'cash_outflows.batch_id')->join('users', 'users.id', '=', 'cash_outflows.actor_id')->where('cash_outflows.id', $outflow->id)->firstOrFail(['cash_outflows.*', 'users.name as actor_name', 'inventory_batches.supplier_name', 'inventory_batches.source_note', 'inventory_batches.reference as batch_reference']);

        return Inertia::render('outflows/show', ['outflow' => $this->present($outflow)]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('viewAny', CashOutflow::class);
        [$query, $filters] = $this->filtered($request);
        AccessAudit::record('outflow.exported', $request->user(), null, 'Owner downloaded filtered money-out ledger.', null, $filters);

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                throw new \RuntimeException('Could not open export stream.');
            }
            try {
                fputcsv($stream, ['reference', 'business_date_nairobi', 'kind', 'category', 'amount_kes', 'method', 'mpesa_code', 'recipient', 'reason', 'recorded_by', 'recorded_at_utc', 'purchase_reference', 'void_sale_id'], ',', '"', '');
                foreach ($query->orderBy('cash_outflows.id')->select(['cash_outflows.*', 'users.name as actor_name', 'inventory_batches.supplier_name', 'inventory_batches.source_note', 'inventory_batches.reference as batch_reference'])->cursor() as $entry) {
                    $row = $this->present($entry);
                    $values = [$row['reference'], $row['effective_on'], $row['kind'], $row['category'], $row['amount'], $row['payment_method'], $row['payment_reference'], $row['recipient'], $row['reason'], $row['actor_name'], $row['created_at'], $row['batch_reference'], $row['void_sale_id']];
                    fputcsv($stream, array_map(fn (mixed $value): string => $this->csvCell((string) ($value ?? '')), $values), ',', '"', '');
                }
            } finally {
                fclose($stream);
            }
        }, 'kaura-money-out.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** @return array{Builder<CashOutflow>, array<string, mixed>} */
    private function filtered(Request $request): array
    {
        $request->mergeIfMissing(['from' => now('Africa/Nairobi')->toDateString(), 'to' => now('Africa/Nairobi')->toDateString()]);
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'kind' => ['nullable', Rule::in(['all', 'expense', 'owner_withdrawal', 'stock_purchase', 'refund'])], 'method' => ['nullable', Rule::in(['all', 'cash', 'mpesa'])]]);
        $filters = ['from' => $request->has('from') ? ($data['from'] ?? '') : now('Africa/Nairobi')->toDateString(), 'to' => $request->has('to') ? ($data['to'] ?? '') : now('Africa/Nairobi')->toDateString(), 'kind' => $data['kind'] ?? 'all', 'method' => $data['method'] ?? 'all'];
        $query = CashOutflow::query()->leftJoin('inventory_batches', 'inventory_batches.id', '=', 'cash_outflows.batch_id')->join('users', 'users.id', '=', 'cash_outflows.actor_id');
        if ($filters['from'] !== '') {
            $query->where('cash_outflows.effective_on', '>=', $filters['from']);
        }
        if ($filters['to'] !== '') {
            $query->where('cash_outflows.effective_on', '<=', $filters['to']);
        }
        if ($filters['kind'] !== 'all') {
            $query->where('cash_outflows.kind', $filters['kind']);
        }
        if ($filters['method'] !== 'all') {
            $query->where('cash_outflows.payment_method', $filters['method']);
        }

        return [$query, $filters];
    }

    /** @return array<string, mixed> */
    private function present(CashOutflow $outflow): array
    {
        return ['reversal' => DB::table('shop_operations')->where('cash_outflow_id', $outflow->id)->whereIn('kind', ['outflow_reversal', 'receipt_reversal'])->first(['id', 'reason', 'business_date']), 'replacement_id' => DB::table('cash_outflows')->where('replaces_outflow_id', $outflow->id)->value('id'), 'replaces_outflow_id' => $outflow->getAttribute('replaces_outflow_id'), ...$outflow->only(['id', 'kind', 'category', 'effective_on', 'payment_method', 'payment_reference', 'actor_name', 'batch_id', 'batch_reference']), 'void_sale_id' => $outflow->getAttribute('void_resolution_id') === null ? null : DB::table('void_resolutions')->join('sale_voids', 'sale_voids.id', '=', 'void_resolutions.sale_void_id')->where('void_resolutions.id', $outflow->getAttribute('void_resolution_id'))->value('sale_voids.sale_id'), 'reference' => 'OUT-'.str_pad((string) $outflow->id, 6, '0', STR_PAD_LEFT), 'amount' => CatalogueValues::money($outflow->amount_minor), 'recipient' => $outflow->getAttribute('recipient') ?? $outflow->getAttribute('supplier_name'), 'reason' => $outflow->getAttribute('reason') ?? $outflow->getAttribute('source_note'), 'created_at' => $outflow->getAttribute('created_at')?->toISOString()];
    }

    private function csvCell(string $value): string
    {
        return preg_match('/^[=+@\-\t\r\n]/', ltrim($value, ' ')) === 1 ? "'".$value : $value;
    }
}
