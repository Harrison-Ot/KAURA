<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Services\PaymentVerificationService;
use App\Services\SaleSettlement;
use App\Support\CatalogueValues;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Payment::class);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'employee_id' => [$request->user()->role === 'owner' ? 'nullable' : 'prohibited', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::in(['all', 'pending', 'verified', 'disputed'])],
        ]);
        $query = Payment::query()->leftJoin('sales', 'sales.id', '=', 'payments.sale_id')->join('users', 'users.id', '=', 'payments.handled_by');
        if ($request->user()->role !== 'owner') {
            $query->where('payments.handled_by', $request->user()->id);
        } elseif (! empty($filters['employee_id'])) {
            $query->where('payments.handled_by', $filters['employee_id']);
        }
        if (! empty($filters['from'])) {
            $query->where('payments.business_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('payments.business_date', '<=', $filters['to']);
        }
        $sum = function (?string $method, ?string $status) use ($query): string {
            $scope = clone $query;
            if ($method !== null) {
                $scope->where('payments.method', $method);
            }
            if ($status !== null) {
                $scope->where('payments.status', $status);
            }

            return CatalogueValues::money((int) $scope->sum('payments.amount_minor'));
        };
        $totals = ['confirmed_total' => $sum(null, 'verified'), 'cash_confirmed' => $sum('cash', 'verified'), 'mpesa_recorded' => $sum('manual_mpesa', null), 'mpesa_verified' => $sum('manual_mpesa', 'verified'), 'mpesa_pending' => $sum('manual_mpesa', 'pending'), 'mpesa_disputed' => $sum('manual_mpesa', 'disputed')];
        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('payments.status', $filters['status']);
        }

        return Inertia::render('payments/index', [
            'payments' => $query->orderByDesc('payments.id')->paginate(20, ['payments.id', 'payments.reference', 'payments.method', 'payments.status', 'payments.amount_minor', 'payments.business_date', 'payments.sale_id', 'payments.mpesa_code', 'payments.receiving_number', 'sales.sold_by', 'sales.reference as sale_reference', 'users.name as handled_by_name'])->withQueryString()
                ->through(fn (Payment $payment): array => [...$payment->only(['id', 'reference', 'method', 'status', 'business_date', 'sale_id', 'sale_reference', 'handled_by_name', 'mpesa_code', 'receiving_number']), 'amount' => CatalogueValues::money($payment->amount_minor), 'can_view_sale' => $request->user()->role === 'owner' || (int) $payment->getAttribute('sold_by') === $request->user()->id]),
            'totals' => $totals,
            'filters' => ['from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? '', 'employee_id' => $filters['employee_id'] ?? '', 'status' => $filters['status'] ?? 'all'],
            'employees' => $request->user()->role === 'owner' ? User::query()->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    public function show(Request $request, Payment $payment): Response
    {
        Gate::authorize('view', $payment);
        $sale = $payment->sale_id === null ? null : Sale::query()->findOrFail($payment->sale_id);
        $canViewSale = $sale !== null && ($request->user()->role === 'owner' || $sale->sold_by === $request->user()->id);
        $decision = DB::table('payment_verifications')->join('users', 'users.id', '=', 'payment_verifications.actor_id')->where('payment_id', $payment->id)->first(['decision', 'reason', 'payment_verifications.created_at', 'users.name as actor_name']);

        return Inertia::render('payments/show', [
            'payment' => [...$payment->only(['id', 'reference', 'method', 'status', 'business_date', 'mpesa_code', 'receiving_number', 'payer_reference', 'purpose']), 'amount' => CatalogueValues::money($payment->amount_minor), 'handled_by_name' => User::query()->where('id', $payment->handled_by)->value('name')],
            'allocations' => $this->allocations($request->user(), $payment),
            'allocationNotes' => $request->user()->role === 'owner' ? $payment->getAttribute('allocation_notes') : null,
            'customer' => $payment->customer_id === null ? null : Customer::query()->findOrFail($payment->customer_id)->only(['name', 'contact']),
            'sale' => $sale === null ? null : [...$sale->only(['id', 'reference']), ...($canViewSale ? ['settlement' => SaleSettlement::summary($sale)] : [])],
            'canViewSale' => $canViewSale,
            'decision' => $decision === null ? null : ['decision' => $decision->decision, 'reason' => $decision->reason, 'actor_name' => $decision->actor_name, 'created_at' => CarbonImmutable::parse($decision->created_at, 'UTC')->toISOString()],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function decide(Request $request, Payment $payment, PaymentVerificationService $service): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['verified', 'disputed'])], 'confirm' => ['accepted'], 'reason' => ['required', 'string', 'max:2000']]);
        $service->decide($request->user(), $payment, $data['decision'], $data['reason']);

        return to_route('payments.show', $payment)->with('status', 'Payment '.$data['decision'].'. The original entry and decision remain in history.');
    }

    public function receipt(Request $request, Payment $payment): View
    {
        Gate::authorize('view', $payment);
        $snapshot = $payment->getAttribute('receipt_snapshot');
        if ($snapshot === null && $payment->sale_id !== null) {
            $sale = Sale::query()->findOrFail($payment->sale_id);
            $snapshot = ['shop' => $sale->getAttribute('shop_snapshot'), 'employee_name' => $sale->getAttribute('employee_name'), 'customer' => $sale->getAttribute('customer_snapshot')];
        }
        abort_if($snapshot === null, 422, 'No historical receipt identity is available.');

        return view('receipts.payment', ['payment' => $payment, 'amount' => CatalogueValues::money($payment->amount_minor), 'snapshot' => $snapshot, 'allocations' => $this->allocations($request->user(), $payment)]);
    }

    /** @return list<array<string, mixed>> */
    private function allocations(User $actor, Payment $payment): array
    {
        return array_values(DB::table('payment_allocations')->join('receivables', 'receivables.id', '=', 'payment_allocations.receivable_id')->leftJoin('sales', 'sales.id', '=', 'receivables.sale_id')->where('payment_id', $payment->id)->orderBy('payment_allocations.id')
            ->get(['payment_allocations.id', 'payment_allocations.amount_minor', 'receivables.id as receivable_id', 'sales.id as sale_id', 'sales.reference', 'sales.sold_by'])
            ->map(fn (object $row): array => ['id' => $row->id, 'reference' => $row->reference ?? 'Opening balance #'.$row->receivable_id, 'sale_id' => $row->sale_id, 'amount' => CatalogueValues::money((int) $row->amount_minor), 'can_view_sale' => $row->sale_id !== null && ($actor->role === 'owner' || (int) $row->sold_by === $actor->id)])->all());
    }
}
