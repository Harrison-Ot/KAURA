<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaleApprovalRequest;
use App\Models\Customer;
use App\Models\SaleRequest;
use App\Models\ShopSettings;
use App\Models\User;
use App\Services\SaleApprovalService;
use App\Support\CatalogueValues;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SaleRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $query = SaleRequest::query()->orderByDesc('id');
        if ($request->user()->role !== 'owner') {
            $query->where('requested_by', $request->user()->id);
        }

        return Inertia::render('approvals/index', ['requests' => $query->paginate(20)->through(fn (SaleRequest $approval): array => $this->present($approval))]);
    }

    public function store(SaleApprovalRequest $request, SaleApprovalService $service): RedirectResponse
    {
        return to_route('approvals.show', $service->request($request->user(), $request->validated()));
    }

    public function show(Request $request, SaleRequest $approval): Response
    {
        Gate::authorize('view', $approval);

        $customer = $request->user()->role === 'owner' && $approval->customer_id !== null ? Customer::query()->findOrFail($approval->customer_id) : null;

        return Inertia::render('approvals/show', ['approval' => $this->present($approval), 'mpesaNumber' => ShopSettings::query()->findOrFail(1)->mpesa_number,
            'creditReview' => $customer === null ? null : ['limit' => CatalogueValues::money($customer->credit_limit_minor), 'outstanding' => CatalogueValues::money((int) DB::table('receivables')->where('customer_id', $customer->id)->sum('remaining_amount_minor'))]]);
    }

    public function decide(Request $request, SaleRequest $approval, SaleApprovalService $service): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => ['required', 'string', 'max:2000'], 'minutes' => ['required', 'integer', 'between:1,60'], 'confirm' => ['accepted']]);
        $service->decide($request->user(), $approval, $data['decision'], $data['reason'], (int) $data['minutes']);

        return to_route('approvals.show', $approval);
    }

    /** @return array<string, mixed> */
    private function present(SaleRequest $approval): array
    {
        return [...$approval->only(['id', 'requested_by', 'customer_id', 'customer_snapshot', 'items', 'payment_method', 'due_on', 'reason', 'decision_reason', 'checkout_key', 'expires_at', 'consumed_sale_id']), 'requested_by_name' => User::query()->where('id', $approval->requested_by)->value('name'),
            'status' => $approval->status === 'approved' && $approval->expires_at?->isPast() ? 'expired' : $approval->status,
            'gross' => CatalogueValues::money($approval->gross_minor), 'discount' => CatalogueValues::money($approval->discount_minor), 'credit' => CatalogueValues::money($approval->credit_minor),
            'total' => CatalogueValues::money($approval->gross_minor - $approval->discount_minor), 'deposit' => CatalogueValues::money($approval->gross_minor - $approval->discount_minor - $approval->credit_minor)];
    }
}
