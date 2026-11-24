<?php

namespace App\Http\Controllers;

use App\Services\CashReconciliationService;
use App\Support\StockArithmetic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CashReconciliationController extends Controller
{
    public function index(Request $request, CashReconciliationService $service): Response
    {
        $today = now('Africa/Nairobi')->toDateString();
        $data = $request->validate(['day' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today]]);
        $day = $data['day'] ?? $today;
        $owner = $request->user()->role === 'owner';
        $accounts = DB::table('users')->when(! $owner, fn ($query) => $query->where('id', $request->user()->id))->orderBy('name')->get(['id', 'name', 'is_active']);

        return Inertia::render('reconciliation/index', ['day' => $day, 'today' => $today, 'isOwner' => $owner, 'rows' => $accounts->map(function (object $account) use ($day, $service): array {
            $handover = DB::table('cash_handovers')->where('employee_id', $account->id)->where('business_date', $day)->first();
            $expected = $service->expected((int) $account->id, $day);

            return ['employee' => $account, 'expected_minor' => (string) $expected, 'handover' => $handover, 'stale' => $handover !== null && (int) $handover->expected_minor !== $expected, 'payments' => DB::table('payments')->where('handled_by', $account->id)->where('business_date', $day)->selectRaw('method, status, SUM(amount_minor) as amount_minor')->groupBy('method', 'status')->get()];
        })]);
    }

    public function store(Request $request, CashReconciliationService $service): RedirectResponse
    {
        $data = $request->validate(['day' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Nairobi')->toDateString()], 'amount' => ['required', 'regex:'.StockArithmetic::TOTAL_RULE], 'note' => ['required', 'string', 'max:2000']]);
        $service->submit($request->user(), $data['day'], $data['amount'], $data['note']);

        return back()->with('status', 'Cash handover submitted for owner review.');
    }

    public function review(Request $request, int $handover, CashReconciliationService $service): RedirectResponse
    {
        $data = $request->validate(['received' => ['required', 'regex:'.StockArithmetic::TOTAL_RULE], 'note' => ['required', 'string', 'max:2000'], 'confirm' => ['accepted']]);
        $service->review($request->user(), $handover, $data['received'], $data['note']);

        return back()->with('status', 'Owner cash review recorded.');
    }
}
