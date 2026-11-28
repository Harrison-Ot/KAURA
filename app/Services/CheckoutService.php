<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleRequest;
use App\Models\ShopSettings;
use App\Models\User;
use App\Support\MpesaCodes;
use App\Support\StockArithmetic;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    /** @param array<string, mixed> $data */
    public function checkout(User $actor, array $data): Sale
    {
        Gate::forUser($actor)->authorize('create', Sale::class);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        try {
            return PostingTransaction::run(fn (): Sale => $this->post($actor, $data, $hash), 'checkout');
        } catch (AuthorizationException $exception) {
            AccessAudit::record('access.denied', $actor, null, 'Checkout key belongs to another employee.');
            throw $exception;
        } catch (UniqueConstraintViolationException $exception) {
            if (($data['payment_method'] ?? null) === 'manual_mpesa' && Payment::query()->where('mpesa_code', $data['mpesa_code'])->exists()) {
                throw ValidationException::withMessages(['mpesa_code' => 'This M-Pesa code is already recorded. No new sale was posted.']);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    private function post(User $actor, array $data, string $hash): Sale
    {
        $settings = ShopSettings::query()->lockForUpdate()->findOrFail(1);
        $existing = Sale::query()->where('submission_key', $data['submission_key'])->first();
        if ($existing) {
            if ($existing->sold_by !== $actor->id) {
                throw new AuthorizationException('Checkout key belongs to another employee.');
            }
            if ($existing->payload_hash !== $hash) {
                throw ValidationException::withMessages(['submission_key' => 'This key already posted a different checkout. Open a new sale.']);
            }

            return $existing;
        }
        $day = CarbonImmutable::now('Africa/Nairobi')->toDateString();
        if ($settings->opening_completed_at === null || ! $settings->shop_name || $settings->opening_as_of > $day) {
            throw ValidationException::withMessages(['checkout' => 'The owner must configure the shop and sign off opening balances before selling.']);
        }
        $method = $data['payment_method'] ?? null;
        if (! in_array($method, ['cash', 'manual_mpesa'], true)) {
            throw ValidationException::withMessages(['payment_method' => 'Choose cash or manual M-Pesa.']);
        }
        $manual = $method === 'manual_mpesa';
        if ($manual) {
            if (! $settings->mpesa_number || ($data['expected_receiving_number'] ?? null) !== $settings->mpesa_number) {
                throw ValidationException::withMessages(['expected_receiving_number' => 'The receiving number is missing or changed. Refresh and confirm where the customer paid; do not attach an old code to a different number.']);
            }
            if (MpesaCodes::exists($data['mpesa_code'])) {
                throw ValidationException::withMessages(['mpesa_code' => 'This M-Pesa code is already recorded. Check the existing payment.']);
            }
        }
        $quote = CartQuote::calculate($data['items']);
        $customer = empty($data['customer_id']) ? null : Customer::query()->where('id', $data['customer_id'])->lockForUpdate()->firstOrFail();
        $items = $quote['items'];
        $gross = $quote['total'];
        $costTotal = $quote['cost_total'];
        $costComplete = $quote['cost_complete'];
        $approval = empty($data['approval_id']) ? null : SaleRequest::query()->lockForUpdate()->findOrFail((int) $data['approval_id']);
        if ($approval !== null) {
            if ($approval->requested_by !== $actor->id || $approval->status !== 'approved' || $approval->expires_at === null || $approval->expires_at->isPast() || $approval->cart_hash !== CartQuote::signature($data) || $approval->gross_minor !== $gross) {
                throw ValidationException::withMessages(['approval_id' => 'Approval is expired, consumed, belongs to another employee or does not match this cart. Request approval again.']);
            }
            if ($approval->customer_snapshot !== $customer?->only(['name', 'contact']) || ($approval->credit_minor > 0 && $approval->due_on < $day)) {
                throw ValidationException::withMessages(['approval_id' => 'Customer identity or promised date changed. Request a fresh approval.']);
            }
        }
        $discount = $approval === null ? 0 : $approval->discount_minor;
        $credit = $approval === null ? 0 : $approval->credit_minor;
        $total = $gross - $discount;
        $deposit = $total - $credit;
        $retained = null;
        $redeemed = 0;
        if (! empty($data['customer_credit_id'])) {
            abort_unless($actor->role === 'owner', 403);
            if ($manual || $customer === null) {
                throw ValidationException::withMessages(['customer_credit_id' => 'Retained credit requires a selected customer and cash checkout for any remaining payment.']);
            }
            $retained = DB::table('customer_credits')->where('id', $data['customer_credit_id'])->lockForUpdate()->first();
            $redeemed = StockArithmetic::totalAmount((string) ($data['retained_credit_amount'] ?? ''), 'retained_credit_amount');
            if ($retained === null || (int) $retained->customer_id !== $customer->id || $redeemed <= 0 || $redeemed > (int) $retained->remaining_amount_minor || $redeemed > $deposit) {
                throw ValidationException::withMessages(['retained_credit_amount' => 'Use a positive amount within this customer?s available retained credit and the sale deposit.']);
            }
            $deposit -= $redeemed;
        }
        if ($credit > 0 && $customer !== null) {
            SaleApprovalService::checkLimit($customer, $manual ? $total : $credit);
        }
        $remainingDiscount = $discount;
        $remainingGross = $gross;
        foreach ($items as &$entry) {
            $entry['gross'] = $entry['amount'];
            $entry['discount'] = $remainingGross === 0 ? 0 : BigDecimal::of($remainingDiscount)->multipliedBy($entry['gross'])->dividedBy($remainingGross, 0, RoundingMode::Down)->toInt();
            $entry['amount'] -= $entry['discount'];
            $remainingGross -= $entry['gross'];
            $remainingDiscount -= $entry['discount'];
        }
        unset($entry);
        $tendered = $manual ? 0 : StockArithmetic::totalAmount((string) $data['cash_tendered'], 'cash_tendered');
        if ($manual && ($deposit === 0 || StockArithmetic::totalAmount((string) $data['mpesa_amount'], 'mpesa_amount') !== $deposit)) {
            throw ValidationException::withMessages(['mpesa_amount' => 'Record the exact, positive approved deposit or full sale amount.']);
        }
        if (! $manual && $tendered < $deposit) {
            throw ValidationException::withMessages(['cash_tendered' => 'Cash received is below the sale total. Unapproved credit cannot be posted.']);
        }
        $sale = Sale::create([
            'reference' => 'SALE-'.Str::ulid(), 'submission_key' => $data['submission_key'], 'payload_hash' => $hash,
            'status' => 'posted', 'sold_by' => $actor->id, 'employee_name' => $actor->name,
            'checkout_method' => $method,
            'customer_id' => $customer?->id, 'customer_snapshot' => $customer?->only(['name', 'contact']),
            'shop_snapshot' => $settings->only(['shop_name', 'address', 'contact']),
            'approval_id' => $approval?->id, 'credit_minor' => $credit, 'promised_due_on' => $approval?->due_on,
            'gross_minor' => $gross, 'discount_minor' => $discount, 'total_minor' => $total, 'cost_total_minor' => $costComplete ? $costTotal : null,
            'cash_tendered_minor' => $tendered, 'change_minor' => $manual ? 0 : $tendered - $deposit, 'business_date' => $day, 'sold_at' => now(),
        ]);
        $sale->reference = 'KS-'.str_replace('-', '', $day).'-'.str_pad((string) $sale->id, 6, '0', STR_PAD_LEFT);
        $sale->save();
        foreach ($items as $entry) {
            $product = $entry['product'];
            $item = SaleItem::create([
                'sale_id' => $sale->id, 'product_id' => $product->id, 'position' => $entry['position'],
                'sku' => $product->sku, 'name' => $product->name, 'unit_label' => $product->unit_label, 'quantity_precision' => $product->quantity_precision,
                'quantity' => StockArithmetic::decimal($entry['quantity']), 'unit_price_minor' => $product->selling_price_minor, 'unit_cost_minor' => $product->average_cost_minor,
                'total_minor' => $entry['amount'], 'cost_total_minor' => $entry['cost'],
                'gross_minor' => $entry['gross'], 'discount_minor' => $entry['discount'],
            ]);
            $product->quantity_on_hand = StockArithmetic::decimal($entry['remaining']);
            $product->inventory_value_minor = $entry['remaining'] === 0 ? 0 : ($entry['cost'] === null ? null : $product->inventory_value_minor - $entry['cost']);
            $product->average_cost_minor = $product->inventory_value_minor !== null && $entry['remaining'] > 0 ? StockArithmetic::average($product->inventory_value_minor, $entry['remaining']) : null;
            $product->version++;
            $product->save();
            DB::table('stock_movements')->insert([
                'product_id' => $product->id, 'batch_item_id' => null, 'sale_item_id' => $item->id, 'opening_product_id' => null, 'kind' => 'sale',
                'quantity_delta' => StockArithmetic::decimal(-$entry['quantity']), 'balance_after' => $product->quantity_on_hand,
                'value_delta_minor' => $entry['cost'] === null ? null : -$entry['cost'], 'effective_on' => $day, 'actor_id' => $actor->id, 'created_at' => now(),
            ]);
        }
        $receivableId = null;
        if ($manual || $credit > 0 || $redeemed > 0) {
            $remaining = $manual ? $total : $credit;
            StockArithmetic::total((int) DB::table('receivables')->sum('remaining_amount_minor'), $remaining, 'items');
            $receivableId = DB::table('receivables')->insertGetId([
                'sale_id' => $sale->id, 'customer_id' => $customer?->id, 'origin' => $credit > 0 || $redeemed > 0 ? 'credit_sale' : 'payment_verification',
                'original_amount_minor' => $total, 'remaining_amount_minor' => $remaining, 'originated_on' => $day, 'due_on' => $approval === null ? $day : ($approval->due_on ?? $day),
                'source_note' => $redeemed > 0 ? 'Sale '.$sale->reference.' settled using retained customer credit and any cash remainder.' : ($credit > 0 ? 'Owner-approved credit sale '.$sale->reference : 'Manual M-Pesa sale '.$sale->reference.' awaiting owner verification.'), 'actor_id' => $actor->id, 'created_at' => now(),
            ]);
            if ($customer !== null) {
                $customer->version++;
                $customer->save();
            }
        }
        if ($deposit > 0 || ($credit === 0 && $redeemed === 0)) {
            $payment = Payment::create([
                'reference' => 'PAY-'.Str::ulid(), 'posting_key' => 'checkout:'.$sale->id, 'sale_id' => $sale->id, 'customer_id' => $customer?->id,
                'handled_by' => $actor->id, 'method' => $method, 'status' => $manual ? 'pending' : 'verified', 'amount_minor' => $deposit, 'business_date' => $day, 'received_at' => now(), 'purpose' => 'checkout',
                'mpesa_code' => $manual ? $data['mpesa_code'] : null, 'receiving_number' => $manual ? $settings->mpesa_number : null,
                'payer_reference' => $manual ? ($data['payer_reference'] ?? null) : null,
            ]);
            if ($receivableId !== null && ! $manual && $deposit > 0) {
                DB::table('payment_allocations')->insert(['payment_id' => $payment->id, 'receivable_id' => $receivableId, 'amount_minor' => $deposit, 'actor_id' => $actor->id, 'created_at' => now()]);
            }
        }
        if ($retained !== null) {
            DB::table('customer_credits')->where('id', $retained->id)->decrement('remaining_amount_minor', $redeemed);
            DB::table('shop_operations')->insert(['submission_key' => (string) Str::uuid(), 'payload_hash' => $hash, 'source_key' => 'checkout:'.$sale->id, 'kind' => 'credit_redemption', 'sale_id' => $sale->id, 'customer_id' => $customer->id, 'receivable_id' => $receivableId, 'customer_credit_id' => $retained->id, 'amount_minor' => $redeemed, 'cash_amount_minor' => 0, 'debt_amount_minor' => $redeemed, 'actor_id' => $actor->id, 'reason' => 'Owner applied retained customer credit at checkout.', 'business_date' => $day, 'created_at' => now()]);
            AccessAudit::record('customer_credit.redeemed', $actor, null, 'Owner applied retained credit at checkout.', ['remaining_minor' => $retained->remaining_amount_minor], ['used_minor' => $redeemed, 'sale_id' => $sale->id], 'customer_credit', (int) $retained->id);
        }
        if ($approval !== null) {
            $approval->fill(['status' => 'consumed', 'consumed_sale_id' => $sale->id])->save();
        }
        AccessAudit::record('sale.posted', $actor, null, $manual ? 'Sale posted with pending M-Pesa and unresolved balance.' : 'Cash sale posted with stock and confirmed net collection.', null, $sale->only(['reference', 'sold_by', 'checkout_method', 'total_minor', 'cost_total_minor', 'cash_tendered_minor', 'change_minor', 'business_date']), 'sale', $sale->id);

        return $sale;
    }
}
