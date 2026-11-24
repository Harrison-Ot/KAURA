<?php

namespace App\Http\Controllers;

use App\Http\Requests\InventoryBatchRequest;
use App\Models\Customer;
use App\Models\InventoryBatch;
use App\Models\InventoryBatchItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SaleVoid;
use App\Models\ShopSettings;
use App\Services\InventoryService;
use App\Support\CatalogueValues;
use App\Support\StockArithmetic;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', InventoryBatch::class);
        $settings = ShopSettings::query()->findOrFail(1);

        return Inertia::render('inventory/index', [
            'batches' => InventoryBatch::query()->latest('id')->paginate(20)->through(fn (InventoryBatch $batch): array => $this->present($batch)),
            'summary' => [
                'opening_as_of' => $settings->opening_as_of, 'opening_completed_at' => $settings->opening_completed_at,
                'stock_listings' => DB::table('stock_movements')->where('kind', 'opening_stock')->count(),
                'missing_counts' => Product::query()->where('is_active', true)->whereNotIn('id', DB::table('stock_movements')->whereNotNull('opening_product_id')->select('opening_product_id'))->count(),
                'debt_total' => CatalogueValues::money((int) DB::table('receivables')->where('origin', 'opening_balance')->sum('original_amount_minor')),
                'known_stock_value' => CatalogueValues::money((int) Product::query()->sum('inventory_value_minor')),
                'unknown_cost_listings' => Product::query()->whereNull('inventory_value_minor')->count(),
            ],
            'today' => CarbonImmutable::now('Africa/Nairobi')->toDateString(), 'status' => $request->session()->get('status'),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', InventoryBatch::class);
        $data = $request->validate(['kind' => ['required', Rule::in(['opening_stock', 'opening_debt', 'stock_receipt'])]]);

        return Inertia::render('inventory/create', [
            'kind' => $data['kind'], 'submission_key' => (string) Str::uuid(),
            'today' => CarbonImmutable::now('Africa/Nairobi')->toDateString(),
            'settings' => ShopSettings::query()->findOrFail(1)->only(['opening_as_of', 'opening_completed_at']),
            'products' => Product::query()->orderBy('name')->get(['id', 'sku', 'name', 'unit_label', 'quantity_precision', 'is_active']),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'contact']),
        ]);
    }

    public function store(InventoryBatchRequest $request, InventoryService $service): RedirectResponse
    {
        Gate::authorize('create', InventoryBatch::class);
        $batch = $service->draft($request->user(), $request->validated());

        return to_route('owner.inventory.show', $batch);
    }

    public function show(InventoryBatch $batch): Response
    {
        Gate::authorize('view', $batch);

        return Inertia::render('inventory/show', [
            'batch' => $this->present($batch),
            'items' => $batch->items->map(fn (InventoryBatchItem $item): array => [
                ...$item->only(['id', 'product_id', 'customer_id', 'label', 'sku', 'unit_label', 'quantity', 'due_on']),
                'unit_cost' => $item->unit_cost_minor === null ? null : CatalogueValues::money($item->unit_cost_minor),
                'amount' => $item->amount_minor === null ? null : CatalogueValues::money($item->amount_minor),
            ]),
            'outflow' => DB::table('cash_outflows')->where('batch_id', $batch->id)->first(['id', 'kind', 'payment_method', 'payment_reference']),
        ]);
    }

    public function post(Request $request, InventoryBatch $batch, InventoryService $service): RedirectResponse
    {
        $data = $request->validate(['confirm' => ['accepted'], 'reason' => ['required', 'string', 'max:1000']]);
        $service->post($request->user(), $batch, $data['reason']);

        return to_route('owner.inventory.show', $batch);
    }

    public function cancel(Request $request, InventoryBatch $batch, InventoryService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $service->cancel($request->user(), $batch, $data['reason']);

        return to_route('owner.inventory.show', $batch);
    }

    public function completeOpening(Request $request, InventoryService $service): RedirectResponse
    {
        $data = $request->validate([
            'confirm' => ['accepted'], 'reason' => ['required', 'string', 'max:1000'],
            'expected_debt_total' => ['required', 'regex:'.StockArithmetic::TOTAL_RULE],
            'effective_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now('Africa/Nairobi')->toDateString()],
        ]);
        $service->completeOpening($request->user(), (string) $data['expected_debt_total'], $data['reason'], $data['effective_on']);

        return to_route('owner.inventory.index')->with('status', 'Opening balances signed off. Deliveries can now be posted.');
    }

    public function template(Request $request): StreamedResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(['opening_stock', 'opening_debt', 'stock_receipt'])]]);
        $headers = $data['kind'] === 'opening_debt' ? ['customer_id', 'amount', 'due_on'] : ['sku', 'unit', 'quantity', 'unit_cost'];

        return response()->streamDownload(function () use ($headers): void {
            $stream = fopen('php://output', 'w');
            if ($stream !== false) {
                fputcsv($stream, $headers, ',', '"', '');
                fclose($stream);
            }
        }, $data['kind'].'-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function stockLedger(Request $request): Response
    {
        $data = $request->validate(['product_id' => ['nullable', 'integer', 'exists:products,id']]);
        $query = DB::table('stock_movements')->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->leftJoin('inventory_batch_items', 'inventory_batch_items.id', '=', 'stock_movements.batch_item_id')
            ->leftJoin('inventory_batches', 'inventory_batches.id', '=', 'inventory_batch_items.batch_id')
            ->leftJoin('sale_items', 'sale_items.id', '=', 'stock_movements.sale_item_id')
            ->leftJoin('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('stock_count_items', 'stock_count_items.id', '=', 'stock_movements.stock_count_item_id')
            ->leftJoin('stock_counts', 'stock_counts.id', '=', 'stock_count_items.stock_count_id')
            ->leftJoin('sale_void_items', 'sale_void_items.id', '=', 'stock_movements.sale_void_item_id')
            ->leftJoin('sale_voids', 'sale_voids.id', '=', 'sale_void_items.sale_void_id')
            ->leftJoin('shop_operation_items', 'shop_operation_items.id', '=', 'stock_movements.shop_operation_item_id')
            ->leftJoin('products as operation_products', 'operation_products.id', '=', 'shop_operation_items.product_id')
            ->leftJoin('sale_items as voided_items', 'voided_items.id', '=', 'sale_void_items.sale_item_id')
            ->leftJoin('sales as voided_sales', 'voided_sales.id', '=', 'sale_voids.sale_id')
            ->join('users', 'users.id', '=', 'stock_movements.actor_id');
        if (! empty($data['product_id'])) {
            $query->where('stock_movements.product_id', $data['product_id']);
        }

        return Inertia::render('inventory/ledger', [
            'movements' => $query->orderByDesc('stock_movements.id')->paginate(30, ['stock_movements.id', 'stock_movements.kind', 'stock_movements.quantity_delta', 'stock_movements.balance_after', 'stock_movements.effective_on', DB::raw('COALESCE(inventory_batch_items.label, sale_items.name, stock_count_items.name, voided_items.name, operation_products.name) as label'), DB::raw('COALESCE(inventory_batch_items.sku, sale_items.sku, stock_count_items.sku, voided_items.sku, operation_products.sku) as sku'), DB::raw('COALESCE(inventory_batch_items.unit_label, sale_items.unit_label, stock_count_items.unit_label, voided_items.unit_label, operation_products.unit_label) as unit_label'), 'inventory_batches.id as batch_id', DB::raw('COALESCE(sales.id, voided_sales.id) as sale_id'), 'stock_counts.id as count_id', DB::raw('COALESCE(inventory_batches.reference, sales.reference, stock_counts.reference, voided_sales.reference) as reference'), 'users.name as actor_name'])->withQueryString(),
            'products' => Product::query()->orderBy('name')->get(['id', 'sku', 'name']), 'product_id' => $data['product_id'] ?? '',
        ]);
    }

    public function statement(Customer $customer): Response
    {
        $receivables = DB::table('receivables')->where('customer_id', $customer->id)->orderBy('originated_on')->orderBy('id')->get();

        return Inertia::render('customers/statement', [
            'adjustments' => DB::table('shop_operations')->where('customer_id', $customer->id)->orderBy('id')->get(['id', 'kind', 'amount_minor', 'debt_amount_minor', 'cash_amount_minor', 'reason', 'business_date']),
            'credits' => DB::table('customer_credits')->join('void_resolutions', 'void_resolutions.id', '=', 'customer_credits.void_resolution_id')->join('sale_voids', 'sale_voids.id', '=', 'void_resolutions.sale_void_id')->where('customer_credits.customer_id', $customer->id)->orderBy('customer_credits.id')->get(['customer_credits.id', 'customer_credits.original_amount_minor', 'customer_credits.remaining_amount_minor', 'void_resolutions.business_date', 'sale_voids.sale_id'])->map(fn (object $credit): array => ['id' => $credit->id, 'sale_id' => $credit->sale_id, 'business_date' => $credit->business_date, 'original' => CatalogueValues::money((int) $credit->original_amount_minor), 'remaining' => CatalogueValues::money((int) $credit->remaining_amount_minor), 'submission_key' => (string) Str::uuid(), 'payout_outflow_id' => DB::table('customer_credit_payouts')->where('customer_credit_id', $credit->id)->value('cash_outflow_id')]),
            'customer' => $customer->only(['id', 'name', 'contact']),
            'unconfirmedInstalments' => Payment::query()->where('customer_id', $customer->id)->where('purpose', 'instalment')->whereIn('status', ['pending', 'disputed'])->orderBy('id')->get()->map(fn (Payment $payment): array => [...$payment->only(['id', 'reference', 'status', 'business_date', 'mpesa_code']), 'amount' => CatalogueValues::money($payment->amount_minor)]),
            'receivables' => $receivables->map(function (object $row): array {
                $payment = $row->sale_id === null ? null : Payment::query()->where('posting_key', 'checkout:'.$row->sale_id)->first();
                $void = $row->sale_id === null ? null : SaleVoid::query()->where('sale_id', $row->sale_id)->first();

                return ['id' => $row->id, 'origin' => $row->origin, 'original_amount' => CatalogueValues::money((int) $row->original_amount_minor), 'remaining_amount' => CatalogueValues::money((int) $row->remaining_amount_minor), 'originated_on' => $row->originated_on, 'due_on' => $row->due_on, 'source_note' => $row->source_note, 'sale_id' => $row->sale_id, 'payment_id' => $payment?->id, 'payment_status' => $payment?->status, 'void_canceled_amount' => $void === null ? null : CatalogueValues::money((int) $void->getAttribute('canceled_debt_minor'))];
            }),
            'allocations' => DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')->join('users', 'users.id', '=', 'payments.handled_by')->whereIn('receivable_id', $receivables->pluck('id'))->orderBy('payment_allocations.id')->get(['payment_allocations.id', 'payments.id as payment_id', 'payments.reference', 'payment_allocations.amount_minor', 'users.name as handled_by_name'])
                ->map(fn (object $row): array => ['id' => $row->id, 'payment_id' => $row->payment_id, 'reference' => $row->reference, 'amount' => CatalogueValues::money((int) $row->amount_minor), 'handled_by_name' => $row->handled_by_name]),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(InventoryBatch $batch): array
    {
        return [
            ...$batch->only(['id', 'reference', 'kind', 'status', 'effective_on', 'source_note', 'supplier_name', 'delivery_reference', 'payment_method', 'payment_reference', 'posted_at', 'decision_reason']),
            'total' => $batch->total_minor === null ? null : CatalogueValues::money($batch->total_minor),
            'amount_paid' => $batch->amount_paid_minor === null ? null : CatalogueValues::money($batch->amount_paid_minor),
        ];
    }
}
