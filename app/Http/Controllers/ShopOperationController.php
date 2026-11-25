<?php

namespace App\Http\Controllers;

use App\Services\ShopOperationService;
use App\Support\StockArithmetic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ShopOperationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('operations/index', [
            'operations' => DB::table('shop_operations')->join('users', 'users.id', '=', 'shop_operations.actor_id')->select('shop_operations.*', 'users.name as actor_name')->latest('shop_operations.id')->paginate(20),
            'submissionKey' => (string) Str::uuid(),
        ]);
    }

    public function context(Request $request): Response
    {
        $data = $request->validate(['sale_id' => ['nullable', 'integer', 'exists:sales,id'], 'customer_id' => ['nullable', 'integer', 'exists:customers,id'], 'outflow_id' => ['nullable', 'integer', 'exists:cash_outflows,id']]);
        $sale = empty($data['sale_id']) ? null : DB::table('sales')->where('id', $data['sale_id'])->first();
        $customer = empty($data['customer_id']) ? null : DB::table('customers')->where('id', $data['customer_id'])->first();

        return Inertia::render('operations/create', [
            'sale' => $sale,
            'items' => $sale === null ? [] : DB::table('sale_items')->where('sale_id', $sale->id)->get()->map(fn (object $item): array => [...(array) $item, 'returned' => DB::table('shop_operation_items')->where('sale_item_id', $item->id)->sum('quantity')]),
            'customer' => $customer,
            'debts' => $customer === null ? [] : DB::table('receivables')->where('customer_id', $customer->id)->where('remaining_amount_minor', '>', 0)->orderBy('originated_on')->orderBy('id')->get(),
            'credits' => $customer === null ? [] : DB::table('customer_credits')->where('customer_id', $customer->id)->where('remaining_amount_minor', '>', 0)->get(),
            'outflow' => empty($data['outflow_id']) ? null : DB::table('cash_outflows')->find($data['outflow_id']),
            'submissionKey' => (string) Str::uuid(),
        ]);
    }

    public function quote(Request $request, ShopOperationService $service): JsonResponse
    {
        $data = $request->validate(['sale_id' => ['required', 'integer', 'exists:sales,id'], 'sale_item_id' => ['required', 'integer', 'exists:sale_items,id'], 'quantity' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'], 'disposition' => ['required', Rule::in(['restock', 'damaged', 'not_received'])]]);

        return response()->json($service->quoteReturn($request->user(), ['sale_id' => $data['sale_id'], 'items' => [['sale_item_id' => $data['sale_item_id'], 'quantity' => $data['quantity'], 'disposition' => $data['disposition']]]]));
    }

    public function store(Request $request, ShopOperationService $service): RedirectResponse
    {
        $kind = $request->input('kind');
        $data = $request->validate([
            'expected_amount_minor' => ['sometimes', 'regex:/^\d{1,16}$/'], 'expected_cash_amount_minor' => ['sometimes', 'regex:/^\d{1,16}$/'], 'expected_debt_amount_minor' => ['sometimes', 'regex:/^\d{1,16}$/'],
            'submission_key' => ['required', 'uuid'], 'kind' => ['required', Rule::in(['return', 'write_off', 'credit_offset', 'reversal'])],
            'sale_id' => [$kind === 'return' ? 'required' : 'prohibited', 'integer', 'exists:sales,id'],
            'items' => [$kind === 'return' ? 'required' : 'prohibited', 'array', 'min:1', 'max:100'],
            'items.*' => ['array:sale_item_id,quantity,disposition'], 'items.*.sale_item_id' => ['required', 'integer', 'distinct', 'exists:sale_items,id'],
            'items.*.quantity' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'], 'items.*.disposition' => ['required', Rule::in(['restock', 'damaged', 'not_received'])],
            'receivable_id' => [in_array($kind, ['write_off', 'credit_offset'], true) ? 'required' : 'prohibited', 'integer', 'exists:receivables,id'],
            'customer_credit_id' => [$kind === 'credit_offset' ? 'required' : 'prohibited', 'integer', 'exists:customer_credits,id'],
            'amount' => [in_array($kind, ['write_off', 'credit_offset'], true) ? 'required' : 'prohibited', 'regex:'.StockArithmetic::TOTAL_RULE],
            'cash_outflow_id' => [$kind === 'reversal' ? 'required' : 'prohibited', 'integer', 'exists:cash_outflows,id'],
            'payment_method' => [in_array($kind, ['return', 'reversal'], true) ? 'required' : 'prohibited', Rule::in(['cash', 'mpesa'])],
            'payment_reference' => [$request->input('payment_method') === 'mpesa' ? 'required' : 'prohibited', 'string', 'max:100', 'regex:/^[A-Z0-9]+$/'],
            'recipient' => [in_array($kind, ['return', 'reversal'], true) ? 'required' : 'prohibited', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:2000'], 'confirm' => ['accepted'],
        ]);
        $service->post($request->user(), $data);

        return to_route('owner.operations.index')->with('status', 'Operation posted. Original records remain preserved.');
    }
}
