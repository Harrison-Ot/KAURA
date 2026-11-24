<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Receivable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceivableAllocator
{
    /** @return array{rows: list<array{receivable: Receivable, capacity: int}>, available: int, notes: list<array<string, mixed>>} */
    public function available(Customer $customer, ?int $excludePayment = null): array
    {
        $rows = [];
        $notes = [];
        $capacity = 0;
        $receivables = Receivable::query()->where('customer_id', $customer->id)->where('remaining_amount_minor', '>', 0)->orderBy('originated_on')->orderBy('id')->lockForUpdate()->get();
        foreach ($receivables as $receivable) {
            $reserved = $receivable->sale_id === null ? 0 : (int) Payment::query()->where('posting_key', 'checkout:'.$receivable->sale_id)->where('status', 'pending')->sum('amount_minor');
            $eligible = max(0, $receivable->remaining_amount_minor - $reserved);
            if ($reserved > 0) {
                $notes[] = ['receivable_id' => $receivable->id, 'reason' => 'pending_checkout_claim_reserved', 'reserved_minor' => min($reserved, $receivable->remaining_amount_minor)];
            }
            $rows[] = ['receivable' => $receivable, 'capacity' => $eligible];
            $capacity += $eligible;
        }
        $pending = Payment::query()->where('customer_id', $customer->id)->where('purpose', 'instalment')->where('status', 'pending')->when($excludePayment !== null, fn ($query) => $query->where('id', '!=', $excludePayment))->sum('amount_minor');

        return ['rows' => $rows, 'available' => max(0, $capacity - (int) $pending), 'notes' => $notes];
    }

    public function allocate(Customer $customer, Payment $payment, int $actorId): void
    {
        $plan = $this->available($customer, $payment->id);
        if ($payment->amount_minor > $plan['available']) {
            throw ValidationException::withMessages(['amount' => 'Amount exceeds the collectible balance or overlaps pending claims. Ask the owner to reconcile first.']);
        }
        $left = $payment->amount_minor;
        foreach ($plan['rows'] as $row) {
            $amount = min($left, $row['capacity']);
            if ($amount === 0) {
                continue;
            }
            $receivable = $row['receivable'];
            $receivable->remaining_amount_minor -= $amount;
            $receivable->save();
            DB::table('payment_allocations')->insert(['payment_id' => $payment->id, 'receivable_id' => $receivable->id, 'amount_minor' => $amount, 'actor_id' => $actorId, 'created_at' => now()]);
            $left -= $amount;
        }
        if ($left !== 0) {
            throw new \LogicException('Payment allocations did not reconcile.');
        }
        $payment->setAttribute('allocation_notes', $plan['notes']);
        $payment->save();
        $customer->version++;
        $customer->save();
    }
}
