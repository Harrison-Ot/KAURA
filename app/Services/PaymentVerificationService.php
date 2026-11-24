<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleVoid;
use App\Models\ShopSettings;
use App\Models\User;
use App\Models\VoidResolution;
use App\Support\StockArithmetic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PaymentVerificationService
{
    public function decide(User $actor, Payment $payment, string $decision, string $reason): void
    {
        Gate::forUser($actor)->authorize('decide', $payment);
        if (! in_array($decision, ['verified', 'disputed'], true) || trim($reason) === '') {
            throw ValidationException::withMessages(['decision' => 'Choose verify or dispute and enter a review reason.']);
        }
        PostingTransaction::run(function () use ($actor, $payment, $decision, $reason): void {
            ShopSettings::query()->lockForUpdate()->findOrFail(1);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== 'pending') {
                if ($payment->status === $decision) {
                    return;
                }
                throw ValidationException::withMessages(['decision' => 'This payment was already '.$payment->status.'. A contradictory decision requires a separate correction workflow.']);
            }
            if ($payment->purpose === 'instalment') {
                $customer = Customer::query()->lockForUpdate()->findOrFail($payment->customer_id);
                $before = ['status' => $payment->status];
                if ($decision === 'verified') {
                    try {
                        app(ReceivableAllocator::class)->allocate($customer, $payment, $actor->id);
                    } catch (ValidationException) {
                        throw ValidationException::withMessages(['decision' => 'The collectible balance changed. Reconcile before verifying this payment.']);
                    }
                }
            } else {
                $sale = Sale::query()->lockForUpdate()->findOrFail($payment->sale_id);
                if ($sale->getAttribute('status') === 'voided') {
                    $void = SaleVoid::query()->where('sale_id', $sale->id)->lockForUpdate()->firstOrFail();
                    if (VoidResolution::query()->where('sale_void_id', $void->id)->exists()) {
                        throw ValidationException::withMessages(['decision' => 'Void funds were already resolved. Reconcile this unexpected claim before any further decision.']);
                    }
                    $before = ['status' => $payment->status, 'void_confirmed_minor' => $void->confirmed_minor];
                    if ($decision === 'verified') {
                        $void->confirmed_minor = StockArithmetic::total($void->confirmed_minor, $payment->amount_minor, 'decision');
                        $void->setAttribute('funds_status', 'needs_review');
                    } elseif ($void->confirmed_minor === 0) {
                        $void->setAttribute('funds_status', 'not_required');
                    }
                    $void->save();
                    // A verified voided-sale claim is held money, not settlement of canceled debt.
                } else {
                    $receivable = Receivable::query()->where('sale_id', $sale->id)->whereIn('origin', ['payment_verification', 'credit_sale'])->lockForUpdate()->firstOrFail();
                    $before = ['status' => $payment->status, 'balance_minor' => $receivable->remaining_amount_minor];
                    if ($decision === 'verified') {
                        if ($payment->amount_minor > $receivable->remaining_amount_minor) {
                            throw ValidationException::withMessages(['decision' => 'This amount exceeds the unresolved balance. Review the sale before verifying.']);
                        }
                        $receivable->remaining_amount_minor -= $payment->amount_minor;
                        $receivable->save();
                        DB::table('payment_allocations')->insert(['payment_id' => $payment->id, 'receivable_id' => $receivable->id, 'amount_minor' => $payment->amount_minor, 'actor_id' => $actor->id, 'created_at' => now()]);
                        if ($receivable->customer_id !== null) {
                            $customer = Customer::query()->lockForUpdate()->findOrFail($receivable->customer_id);
                            $customer->version++;
                            $customer->save();
                        }
                    }
                }
            }
            $payment->status = $decision;
            $payment->save();
            DB::table('payment_verifications')->insert(['payment_id' => $payment->id, 'actor_id' => $actor->id, 'decision' => $decision, 'reason' => $reason, 'created_at' => now()]);
            AccessAudit::record('payment.'.$decision, $actor, null, $reason, $before, ['status' => $decision, 'amount_minor' => $payment->amount_minor, 'mpesa_code' => $payment->mpesa_code, 'receiving_number' => $payment->receiving_number], 'payment', $payment->id);
        }, 'decision');
    }
}
