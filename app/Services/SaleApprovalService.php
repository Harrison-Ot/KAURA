<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\SaleRequest;
use App\Models\ShopSettings;
use App\Models\User;
use App\Support\StockArithmetic;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaleApprovalService
{
    /** @param array<string, mixed> $data */
    public function request(User $actor, array $data): SaleRequest
    {
        abort_unless($actor->is_active, 403);

        return PostingTransaction::run(function () use ($actor, $data): SaleRequest {
            $settings = ShopSettings::query()->lockForUpdate()->findOrFail(1);
            if ($settings->opening_completed_at === null) {
                throw ValidationException::withMessages(['approval' => 'Opening balances must be signed off first.']);
            }
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = SaleRequest::query()->where('submission_key', $data['submission_key'])->first();
            if ($existing) {
                abort_unless($existing->requested_by === $actor->id, 403);
                if ($existing->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['approval' => 'This key belongs to a different request. Start a new draft.']);
                }

                return $existing;
            }
            $quote = CartQuote::calculate($data['items']);
            $discount = StockArithmetic::totalAmount($data['discount_amount'], 'discount_amount');
            $credit = StockArithmetic::totalAmount($data['credit_amount'], 'credit_amount');
            if ($discount > $quote['total'] || $credit > $quote['total'] - $discount || ($discount === 0 && $credit === 0)) {
                throw ValidationException::withMessages(['approval' => 'Request a positive discount or credit within the sale total.']);
            }
            $customer = empty($data['customer_id']) ? null : Customer::query()->where('id', $data['customer_id'])->lockForUpdate()->firstOrFail();
            if ($credit > 0) {
                if ($customer === null || empty($data['due_on']) || $data['due_on'] < CarbonImmutable::now('Africa/Nairobi')->toDateString()) {
                    throw ValidationException::withMessages(['due_on' => 'Credit requires a named customer and a promised date today or later.']);
                }
                self::checkLimit($customer, $data['payment_method'] === 'manual_mpesa' ? $quote['total'] - $discount : $credit);
            }
            if ($data['payment_method'] === 'manual_mpesa' && ($quote['total'] - $discount - $credit === 0 || ! $settings->mpesa_number)) {
                throw ValidationException::withMessages(['approval' => 'Manual M-Pesa needs a positive deposit and a configured receiving number. Use cash with zero tender for full credit.']);
            }
            $request = SaleRequest::create([
                'submission_key' => $data['submission_key'], 'checkout_key' => (string) Str::uuid(), 'payload_hash' => $hash, 'cart_hash' => CartQuote::signature($data),
                'requested_by' => $actor->id, 'customer_id' => $customer?->id, 'customer_snapshot' => $customer?->only(['name', 'contact']),
                'items' => array_map(fn (array $entry): array => [...$data['items'][$entry['position'] - 1], 'name' => $entry['product']->name, 'sku' => $entry['product']->sku, 'unit_label' => $entry['product']->unit_label], $quote['items']),
                'payment_method' => $data['payment_method'], 'gross_minor' => $quote['total'], 'discount_minor' => $discount, 'credit_minor' => $credit,
                'due_on' => $credit > 0 ? $data['due_on'] : null, 'reason' => $data['reason'], 'status' => 'pending',
            ]);
            AccessAudit::record('sale_approval.requested', $actor, null, $data['reason'], null, $request->only(['gross_minor', 'discount_minor', 'credit_minor', 'customer_id', 'due_on']), 'sale_request', $request->id);

            return $request;
        }, 'approval');
    }

    public function decide(User $actor, SaleRequest $request, string $decision, string $reason, int $minutes): void
    {
        Gate::forUser($actor)->authorize('decide', $request);
        if (! in_array($decision, ['approved', 'rejected'], true) || trim($reason) === '' || $minutes < 1 || $minutes > 60) {
            throw ValidationException::withMessages(['decision' => 'Choose approve or reject, enter a reason and a lifetime of 1–60 minutes.']);
        }
        PostingTransaction::run(function () use ($actor, $request, $decision, $reason, $minutes): void {
            ShopSettings::query()->lockForUpdate()->findOrFail(1);
            $request = SaleRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== 'pending') {
                if ($request->status === $decision) {
                    return;
                }
                throw ValidationException::withMessages(['decision' => 'This request was already decided. Create a fresh request for changed terms.']);
            }
            if ($decision === 'approved') {
                CartQuote::calculate($request->items);
                if ($request->credit_minor > 0) {
                    $customer = Customer::query()->lockForUpdate()->findOrFail($request->customer_id);
                    self::checkLimit($customer, $request->payment_method === 'manual_mpesa' ? $request->gross_minor - $request->discount_minor : $request->credit_minor);
                }
            }
            $request->fill(['status' => $decision, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_reason' => $reason, 'expires_at' => $decision === 'approved' ? now()->addMinutes($minutes) : null])->save();
            AccessAudit::record('sale_approval.'.$decision, $actor, null, $reason, ['status' => 'pending'], $request->only(['status', 'discount_minor', 'credit_minor', 'expires_at']), 'sale_request', $request->id);
        }, 'decision');
    }

    public static function checkLimit(Customer $customer, int $exposure): void
    {
        $owed = (int) DB::table('receivables')->where('customer_id', $customer->id)->sum('remaining_amount_minor');
        if ($exposure > $customer->credit_limit_minor - $owed) {
            throw ValidationException::withMessages(['approval' => 'Customer credit limit would be exceeded. The owner must change the limit separately first. Pending M-Pesa remains outstanding.']);
        }
    }
}
