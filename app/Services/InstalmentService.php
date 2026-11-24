<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\ShopSettings;
use App\Models\User;
use App\Support\MpesaCodes;
use App\Support\StockArithmetic;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InstalmentService
{
    /** @param array<string, mixed> $data */
    public function collect(User $actor, array $data): Payment
    {
        abort_unless($actor->is_active, 403);

        return PostingTransaction::run(function () use ($actor, $data): Payment {
            $settings = ShopSettings::query()->lockForUpdate()->findOrFail(1);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $key = 'instalment:'.$data['submission_key'];
            $existing = Payment::query()->where('posting_key', $key)->first();
            if ($existing) {
                abort_unless($existing->handled_by === $actor->id, 403);
                if ($existing->getAttribute('payload_hash') !== $hash) {
                    throw ValidationException::withMessages(['submission_key' => 'This key belongs to another collection. Start a new payment.']);
                }

                return $existing;
            }
            if ($settings->opening_completed_at === null) {
                throw ValidationException::withMessages(['amount' => 'Opening balances must be signed off before collections.']);
            }
            $customer = Customer::query()->lockForUpdate()->findOrFail((int) $data['customer_id']);
            $amount = StockArithmetic::totalAmount($data['amount'], 'amount');
            $allocator = app(ReceivableAllocator::class);
            if ($amount === 0 || $amount > $allocator->available($customer)['available']) {
                throw ValidationException::withMessages(['amount' => 'Amount exceeds the collectible balance or overlaps pending claims. Ask the owner to reconcile first.']);
            }
            $manual = $data['method'] === 'manual_mpesa';
            if ($manual && (! $settings->mpesa_number || $settings->mpesa_number !== $data['expected_receiving_number'])) {
                throw ValidationException::withMessages(['expected_receiving_number' => 'Receiving number changed. Refresh and check the payment destination.']);
            }
            if ($manual && MpesaCodes::exists($data['mpesa_code'])) {
                throw ValidationException::withMessages(['mpesa_code' => 'This M-Pesa code was already recorded.']);
            }
            $payment = Payment::create([
                'reference' => 'PAY-'.Str::ulid(), 'posting_key' => $key, 'payload_hash' => $hash, 'purpose' => 'instalment', 'customer_id' => $customer->id,
                'handled_by' => $actor->id, 'method' => $data['method'], 'status' => $manual ? 'pending' : 'verified', 'amount_minor' => $amount,
                'business_date' => CarbonImmutable::now('Africa/Nairobi')->toDateString(), 'received_at' => now(),
                'receipt_snapshot' => ['shop' => $settings->only(['shop_name', 'address', 'contact']), 'employee_name' => $actor->name, 'customer' => $customer->only(['name', 'contact'])],
                'mpesa_code' => $manual ? $data['mpesa_code'] : null, 'receiving_number' => $manual ? $settings->mpesa_number : null, 'payer_reference' => $manual ? ($data['payer_reference'] ?? null) : null,
            ]);
            if (! $manual) {
                $allocator->allocate($customer, $payment, $actor->id);
            }
            AccessAudit::record('instalment.recorded', $actor, null, $manual ? 'Pending M-Pesa instalment; debt unchanged.' : 'Cash instalment allocated oldest-first.', null, $payment->only(['customer_id', 'amount_minor', 'status', 'handled_by', 'allocation_notes']), 'payment', $payment->id);

            return $payment;
        }, 'amount');
    }
}
