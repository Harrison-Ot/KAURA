<?php

namespace App\Http\Controllers;

use App\Http\Requests\VoidResolutionRequest;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleVoid;
use App\Models\VoidResolution;
use App\Services\SaleVoidService;
use App\Services\VoidResolutionService;
use App\Support\CatalogueValues;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SaleVoidController extends Controller
{
    public function store(Request $request, Sale $sale, SaleVoidService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'confirm' => ['accepted']]);
        $service->void($request->user(), $sale, $data['reason']);

        return to_route('sales.show', $sale);
    }

    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['open', 'all'])]]);
        $query = SaleVoid::query()->join('sales', 'sales.id', '=', 'sale_voids.sale_id')->join('users', 'users.id', '=', 'sale_voids.actor_id');
        if (($data['status'] ?? 'open') === 'open') {
            $query->where(fn ($query) => $query->where('stock_status', 'pending')->orWhere(fn ($funds) => $funds->where('funds_status', 'needs_review')->whereNotExists(fn ($resolved) => $resolved->selectRaw('1')->from('void_resolutions')->whereColumn('void_resolutions.sale_void_id', 'sale_voids.id'))));
        }

        return Inertia::render('voids/index', ['voids' => $query->orderByDesc('sale_voids.id')->paginate(20, ['sale_voids.*', 'sales.reference', 'sales.customer_id', 'sales.employee_name', 'users.name as actor_name'])->withQueryString()->through(function (SaleVoid $void): array {
            $resolution = VoidResolution::query()->where('sale_void_id', $void->id)->first();

            return [...$void->only(['id', 'sale_id', 'reference', 'customer_id', 'employee_name', 'reason', 'actor_name', 'created_at', 'stock_status', 'stock_reason', 'funds_status']), 'submission_key' => (string) Str::uuid(), 'resolution' => $resolution === null ? null : [...$resolution->only(['id', 'kind', 'recipient', 'reason', 'payment_method', 'payment_reference', 'business_date']), 'amount' => CatalogueValues::money($resolution->amount_minor)], 'canceled_debt' => CatalogueValues::money((int) $void->getAttribute('canceled_debt_minor')), 'confirmed_funds' => CatalogueValues::money($void->confirmed_minor), 'pending_claims' => CatalogueValues::money((int) DB::table('payments')->where('sale_id', $void->sale_id)->where('status', 'pending')->sum('amount_minor'))];
        }), 'filter' => $data['status'] ?? 'open', 'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'contact'])]);
    }

    public function resolve(VoidResolutionRequest $request, SaleVoid $void, VoidResolutionService $service): RedirectResponse
    {
        $service->resolve($request->user(), $void, $request->validated());

        return to_route('owner.voids.index', ['status' => 'all']);
    }

    public function stock(Request $request, SaleVoid $void, SaleVoidService $service): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['restocked', 'not_restocked'])], 'reason' => ['required', 'string', 'max:2000'], 'confirm' => ['accepted']]);
        $service->decideStock($request->user(), $void, $data['decision'], $data['reason']);

        return to_route('sales.show', $void->sale_id);
    }
}
