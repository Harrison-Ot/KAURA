<?php

namespace App\Http\Controllers;

use App\Services\CustomerCreditPayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomerCreditPayoutController extends Controller
{
    public function store(Request $request, int $credit, CustomerCreditPayoutService $service): RedirectResponse
    {
        if (is_string($request->input('payment_reference'))) {
            $request->merge(['payment_reference' => Str::upper(trim($request->input('payment_reference')))]);
        }
        $data = $request->validate(['submission_key' => ['required', 'uuid'], 'payment_method' => ['required', Rule::in(['cash', 'mpesa'])], 'payment_reference' => [$request->input('payment_method') === 'mpesa' ? 'required' : 'prohibited', 'string', 'regex:/^[A-Z0-9]{6,30}$/'], 'recipient' => ['required', 'string', 'max:255'], 'reason' => ['required', 'string', 'max:2000'], 'confirm' => ['accepted'], 'amount' => ['prohibited'], 'amount_minor' => ['prohibited'], 'actor_id' => ['prohibited']]);

        return to_route('owner.outflows.show', $service->pay($request->user(), $credit, $data));
    }
}
