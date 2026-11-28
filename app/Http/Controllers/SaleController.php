<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleVoid;
use App\Models\ShopSettings;
use App\Models\User;
use App\Models\VoidResolution;
use App\Services\CheckoutService;
use App\Services\SaleSettlement;
use App\Support\CatalogueValues;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Sale::class);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'employee_id' => [$request->user()->role === 'owner' ? 'nullable' : 'prohibited', 'integer', 'exists:users,id'],
        ]);
        $query = Sale::query()->with('payments')->orderByDesc('id');
        if ($request->user()->role !== 'owner') {
            $query->where('sold_by', $request->user()->id);
        } elseif (! empty($filters['employee_id'])) {
            $query->where('sold_by', $filters['employee_id']);
        }
        if (! empty($filters['from'])) {
            $query->where('business_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('business_date', '<=', $filters['to']);
        }

        return Inertia::render('sales/index', [
            'sales' => $query->paginate(20)->withQueryString()->through(fn (Sale $sale): array => $this->summary($sale)),
            'filters' => ['from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? '', 'employee_id' => $filters['employee_id'] ?? ''],
            'employees' => $request->user()->role === 'owner' ? User::query()->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Sale::class);
        $settings = ShopSettings::query()->findOrFail(1);

        return Inertia::render('sales/create', [
            'submission_key' => (string) Str::uuid(), 'openingReady' => $settings->opening_completed_at !== null && $settings->shop_name !== null,
            'mpesaNumber' => $settings->mpesa_number,
            'retainedCredits' => auth()->user()->role === 'owner' ? DB::table('customer_credits')->where('remaining_amount_minor', '>', 0)->get(['id', 'customer_id', 'remaining_amount_minor']) : [],
            'products' => Product::query()->where('is_active', true)->orderBy('name')->get()->map(fn (Product $product): array => [
                ...$product->only(['id', 'sku', 'name', 'barcode', 'unit_label', 'quantity_precision', 'quantity_on_hand']),
                'selling_price' => CatalogueValues::money($product->selling_price_minor),
            ]),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'contact']),
        ]);
    }

    public function store(CheckoutRequest $request, CheckoutService $service): RedirectResponse
    {
        $sale = $service->checkout($request->user(), $request->validated());

        return to_route('sales.show', $sale);
    }

    public function show(Request $request, Sale $sale): Response
    {
        Gate::authorize('view', $sale);

        return Inertia::render('sales/show', ['sale' => $this->details($sale, $request->user()->role === 'owner')]);
    }

    public function receipt(Sale $sale): View
    {
        Gate::authorize('view', $sale);

        return view('receipts.sale', ['sale' => $this->details($sale, false)]);
    }

    /** @return array<string, mixed> */
    private function summary(Sale $sale): array
    {
        return [...$sale->only(['id', 'reference', 'status', 'employee_name', 'business_date', 'checkout_method']), 'sold_at' => $sale->sold_at->toISOString(), 'total' => CatalogueValues::money($sale->total_minor), 'settlement' => SaleSettlement::summary($sale)];
    }

    /** @return array<string, mixed> */
    private function details(Sale $sale, bool $owner): array
    {
        $void = SaleVoid::query()->where('sale_id', $sale->id)->first();
        $resolution = $void === null ? null : VoidResolution::query()->where('sale_void_id', $void->id)->first();
        $creditPayout = $resolution === null ? null : DB::table('customer_credit_payouts')->join('customer_credits', 'customer_credits.id', '=', 'customer_credit_payouts.customer_credit_id')->where('customer_credits.void_resolution_id', $resolution->id)->value('customer_credit_payouts.amount_minor');
        $result = [
            'operations' => DB::table('shop_operations')->where('sale_id', $sale->id)->orderBy('id')->get(['id', 'kind', 'amount_minor', 'cash_amount_minor', 'debt_amount_minor', 'reason', 'business_date']),
            'void' => $void === null ? null : [...$void->only(['id', 'reason', 'created_at', 'stock_status', 'stock_reason', 'funds_status']), 'resolution' => $resolution === null ? null : [...$resolution->only(['kind', 'business_date']), 'amount' => CatalogueValues::money($resolution->amount_minor), 'credit_payout_amount' => $creditPayout === null ? null : CatalogueValues::money((int) $creditPayout)], 'actor_name' => User::query()->where('id', $void->getAttribute('actor_id'))->value('name'), 'confirmed_funds' => CatalogueValues::money($void->confirmed_minor), 'canceled_debt' => CatalogueValues::money((int) $void->getAttribute('canceled_debt_minor'))],
            ...$this->summary($sale), ...$sale->only(['shop_snapshot', 'customer_snapshot', 'approval_id', 'promised_due_on']),
            'approved_credit' => CatalogueValues::money((int) $sale->getAttribute('credit_minor')),
            'cash_tendered' => CatalogueValues::money($sale->cash_tendered_minor), 'change' => CatalogueValues::money($sale->change_minor),
            'gross' => CatalogueValues::money($sale->getAttribute('gross_minor')), 'discount' => CatalogueValues::money($sale->getAttribute('discount_minor')),
            'items' => $sale->items->map(function (SaleItem $item) use ($owner): array {
                $row = [...$item->only(['id', 'sku', 'name', 'unit_label', 'quantity_precision', 'quantity']), 'unit_price' => CatalogueValues::money($item->unit_price_minor), 'total' => CatalogueValues::money($item->total_minor), 'discount' => CatalogueValues::money((int) $item->getAttribute('discount_minor'))];
                if ($owner) {
                    $row['unit_cost'] = $item->unit_cost_minor === null ? null : CatalogueValues::money($item->unit_cost_minor);
                    $row['cost_total'] = $item->cost_total_minor === null ? null : CatalogueValues::money($item->cost_total_minor);
                }

                return $row;
            })->all(),
            'payments' => $sale->payments->map(fn (Payment $payment): array => [
                ...$payment->only(['id', 'reference', 'method', 'status', 'mpesa_code', 'receiving_number', 'payer_reference']), 'amount' => CatalogueValues::money($payment->amount_minor),
            ])->all(),
        ];
        if ($owner) {
            $result['cost_total'] = $sale->cost_total_minor === null ? null : CatalogueValues::money($sale->cost_total_minor);
        }

        return $result;
    }
}
